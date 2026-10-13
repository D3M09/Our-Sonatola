<?php
declare(strict_types=1);

/**
 * Browser-based installer for the Sonatola backend (no shell required).
 *
 * Security properties (see docs/setup.md):
 * - Mutations happen on POST only, gated by wizard session + CSRF + 10-min idle expiry.
 * - GET requests render forms/status and never mutate state.
 * - The DB password is never stored in the session; each authorizing POST
 *   re-supplies it, and it is unset within the same request.
 * - .env is never overwritten; uploads/storage stay outside the docroot.
 * - Refuses UNSAFE/ambiguous database states instead of repairing them.
 */

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/src/Setup/Requirements.php';
require_once $ROOT . '/src/Setup/SetupSession.php';
require_once $ROOT . '/src/Setup/InstallerState.php';
require_once $ROOT . '/src/Setup/EnvWriter.php';
require_once $ROOT . '/src/Setup/InstallLock.php';
require_once $ROOT . '/src/Setup/InstallRunner.php';
require_once $ROOT . '/src/Core/SqlSplitter.php';
require_once $ROOT . '/src/Core/AppException.php';
require_once $ROOT . '/src/Core/Validator.php';
require_once $ROOT . '/src/Core/Esc.php';
require_once $ROOT . '/src/Core/Auth.php';
require_once $ROOT . '/src/Core/Logger.php';
require_once $ROOT . '/src/Core/Request.php';
require_once $ROOT . '/src/Core/Audit.php';
require_once $ROOT . '/src/Repositories/BaseRepository.php';
require_once $ROOT . '/src/Repositories/UserRepository.php';
Config::load($ROOT);

// Session for the wizard only (independent key; see SetupSession).
session_name(Config::get('SESSION_NAME', 'sonatola_sid'));
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true, 'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
header('X-Robots-Tag: noindex, nofollow');

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$errors = [];
$notices = [];

/** @return PDO read-write connection to the wizard target DB (dies safe on failure) */
function setup_pdo(array $db, ?string $withDb): PDO
{
    $dsn = $withDb !== null
        ? sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], (int) $db['port'], $withDb, $db['charset'] ?? 'utf8mb4')
        : sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], (int) $db['port'], $db['charset'] ?? 'utf8mb4');
    return new PDO($dsn, (string) $db['user'], (string) $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function setup_fail(string $msg): void
{
    try {
        Logger::default()->warning('setup: ' . $msg);
    } catch (Throwable) {
    }
    throw AppException::badRequest($msg);
}

// ---- Read-only facts for rendering (no writes on GET or POST preamble) ----
$lockPresent = InstallLock::exists($ROOT);
$envExists = is_file($ROOT . '/.env');
$progress = InstallLock::readProgress($ROOT);

// ---- POST handling: every mutation requires live session + valid CSRF ----
// Exception: 'start' creates the session itself and carries no side effects
// beyond initializing empty wizard state.
if ($isPost) {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'start') {
            SetupSession::init();
            $notices[] = 'Setup session started.';
        } else {
        if (!SetupSession::touch()) {
            SetupSession::clear();
            setup_fail('Setup session expired (10-minute limit). Please start over.');
        }
        if (!SetupSession::validateCsrf($_POST['csrf'] ?? null)) {
            setup_fail('Invalid security token. Please retry the current step.');
        }
        $ses = SetupSession::get();

        if ($action === 'probe') {
            // DatabaseStep: validate shape, then read-only probe. Password used here only.
            $db = [
                'host' => trim((string) ($_POST['db_host'] ?? '')),
                'port' => trim((string) ($_POST['db_port'] ?? '')),
                'name' => trim((string) ($_POST['db_name'] ?? '')),
                'user' => trim((string) ($_POST['db_user'] ?? '')),
                'pass' => (string) ($_POST['db_pass'] ?? ''),
                'charset' => 'utf8mb4',
            ];
            if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
                setup_fail('Database host, name and user are required.');
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $db['name'])) {
                setup_fail('Database name may contain letters, digits and underscores only.');
            }
            $port = (int) $db['port'];
            if ($port < 1 || $port > 65535) {
                setup_fail('Database port is out of range.');
            }
            $db['port'] = (string) $port;
            try {
                $p = setup_pdo($db, null);
                $server = (string) $p->query('SELECT VERSION()')->fetchColumn();
                $st = InstallRunner::probeState($p, $db['name']);
                $tables = InstallerState::tableNames($p, $db['name']);
            } catch (Throwable) {
                setup_fail('Cannot reach the database. Check that MySQL is running and the host, port, user and password are correct.');
            } finally {
                unset($db['pass']);
            }
            SetupSession::setDbFacts($db);
            SetupSession::markProgress('probed');
            InstallLock::writeProgress($ROOT, array_merge(InstallLock::readProgress($ROOT), ['probed' => true]));
            $notices[] = 'Connected (' . $server . '). Observed tables: ' . count($tables) . '.';
        } elseif ($action === 'write_env') {
            // Password re-supplied for this single request; never persisted.
            $s = SetupSession::get();
            $dbFacts = is_array($s['db'] ?? null) ? $s['db'] : null;
            if ($dbFacts === null) {
                setup_fail('Complete the database step first.');
            }
            $pass = (string) ($_POST['db_pass'] ?? '');
            try {
                $content = EnvWriter::render(
                    (string) $dbFacts['host'], (string) $dbFacts['port'],
                    (string) $dbFacts['name'], (string) $dbFacts['user'], $pass
                );
            } finally {
                unset($pass);
            }
            $res = EnvWriter::write($ROOT, $content);
            SetupSession::markProgress('env-written');
            InstallLock::writeProgress($ROOT, array_merge(InstallLock::readProgress($ROOT), ['env-written' => true]));
            $notices[] = $res['chmod_ok']
                ? 'Configuration written (.env, restricted permissions).'
                : 'Configuration written, but strict file permissions could not be applied — verify via File Manager.';
        } elseif ($action === 'migrate') {
            $cfg = Config::db();
            $dbName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $cfg['name']);
            $pdo = setup_pdo($cfg, null);
            $verdict = InstallerState::inspect($pdo, $dbName, InstallLock::readProgress($ROOT), InstallLock::exists($ROOT));
            if ($verdict['state'] !== InstallerState::FRESH && $verdict['state'] !== InstallerState::INTERRUPTED) {
                setup_fail('Refusing migration: ' . $verdict['reason'] . '. See recovery notes below.');
            }
            InstallRunner::ensureDatabase($pdo, $dbName);
            $first = $ROOT . '/database/migrations/001_schema_migrations.sql';
            if (is_file($first) && !in_array('001_schema_migrations', $verdict['applied'], true)) {
                InstallRunner::applyMigrationFile($pdo, $first);
            }
            $appliedNow = array_column($pdo->query('SELECT version FROM schema_migrations')->fetchAll(), 'version');
            foreach (InstallRunner::migrationFiles($ROOT . '/database/migrations') as $f) {
                if (in_array(basename($f, '.sql'), $appliedNow, true)) {
                    continue;
                }
                $sql = (string) file_get_contents($f);
                if (InstallRunner::containsDestructiveDrop($sql)) {
                    setup_fail('Refusing migration containing DROP statements: ' . basename($f));
                }
                InstallRunner::applyMigrationFile($pdo, $f);
            }
            $done = array_column($pdo->query('SELECT version FROM schema_migrations')->fetchAll(), 'version');
            InstallLock::writeProgress($ROOT, array_merge(InstallLock::readProgress($ROOT), ['migrated_versions' => count($done)]));
            SetupSession::markProgress('migrated');
            $notices[] = 'Migrations applied: ' . count($done) . ' version(s).';
        } elseif ($action === 'seed') {
            $cfg = Config::db();
            $dbName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $cfg['name']);
            $pdo = setup_pdo($cfg, $dbName);
            $verdict = InstallerState::inspect($pdo, $dbName, InstallLock::readProgress($ROOT), InstallLock::exists($ROOT));
            if (count($verdict['applied']) !== 11) {
                setup_fail('Seeds require a freshly completed migration (11 versions). Refusing.');
            }
            if ((int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() > 0) {
                setup_fail('Settings already exist — seeds are not rerun against an existing installation.');
            }
            foreach (InstallRunner::seedFiles($ROOT . '/database/seeds') as $s) {
                InstallRunner::applySeedFile($pdo, $s);
            }
            InstallLock::writeProgress($ROOT, array_merge(InstallLock::readProgress($ROOT), ['seeded' => true]));
            SetupSession::markProgress('seeded');
            $notices[] = 'Seed data applied and verified.';
        } elseif ($action === 'admin') {
            // Fail closed: only the wizard run that migrated+seeded this database
            // (proven by its own progress marker) may create the first admin.
            $marker = InstallLock::readProgress($ROOT);
            if ((int) ($marker['migrated_versions'] ?? 0) !== 11 || empty($marker['seeded'])) {
                setup_fail('Administrator creation requires this wizard to have completed migration and seeding first.');
            }
            $cfg = Config::db();
            $dbName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $cfg['name']);
            $pdo = setup_pdo($cfg, $dbName);
            $name = trim((string) ($_POST['admin_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
            $pass = (string) ($_POST['admin_password'] ?? '');
            $phone = trim((string) ($_POST['admin_phone'] ?? ''));
            try {
                if ($name === '' || $email === '' || $pass === '') {
                    setup_fail('Administrator name, email and password are required.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    setup_fail('Invalid administrator email address.');
                }
                if (strlen($pass) < 12) {
                    setup_fail('Administrator password must be at least 12 characters.');
                }
                $upazilaId = null;
                try {
                    $r = $pdo->query("SELECT id FROM upazilas WHERE name_en = 'Sonatola' LIMIT 1")->fetch();
                    $upazilaId = $r ? (int) $r['id'] : null;
                } catch (Throwable) {
                }
                $uid = InstallRunner::createSuperAdmin(
                    $pdo, $name, $email, Auth::hashPassword($pass),
                    $phone !== '' ? $phone : null, $upazilaId
                );
            } finally {
                unset($pass);
            }
            InstallLock::writeProgress($ROOT, array_merge(InstallLock::readProgress($ROOT), ['admin_id' => $uid]));
            InstallLock::create($ROOT, ['migrated_versions' => 11, 'seeded' => true, 'admin_id' => $uid]);
            SetupSession::clear();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $notices[] = 'Administrator created and installation locked.';
        } elseif ($action === 'disable') {
            if (!InstallLock::exists($ROOT)) {
                setup_fail('Installation is not locked yet — nothing to disable.');
            }
            $renamed = @rename(__FILE__, dirname(__FILE__) . '/setup.disabled.php');
            $notices[] = $renamed
                ? 'Installer disabled (renamed to setup.disabled.php).'
                : 'Could not rename installer — remove public/setup.php via File Manager.';
        } else {
            setup_fail('Unknown action.');
        }
        } // end non-start actions (session + CSRF gated)
    } catch (AppException $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable) {
        try {
            Logger::default()->error('setup: unexpected failure');
        } catch (Throwable) {
        }
        $errors[] = 'Setup step failed. Check storage/logs and retry the current step.';
    }
    // Passwords from POST are never retained past this request.
    unset($_POST['db_pass'], $_POST['admin_password']);
}

// ---- Render (read-only; reached by GET and after POST) ----
$lockPresent = InstallLock::exists($ROOT);
$envExists = is_file($ROOT . '/.env');
$progress = InstallLock::readProgress($ROOT);
$sessionLive = SetupSession::touch() || SetupSession::get() !== [];
$csrf = SetupSession::csrfToken();
$requirements = SetupRequirements::check($ROOT);
$reqOk = SetupRequirements::allOk($requirements);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sonatola — Installer</title>
</head>
<body style="font-family:system-ui,sans-serif;margin:2rem;max-width:46rem">
<h1>Sonatola Upazila Digital Platform — Installer</h1>
<?php foreach ($errors as $e): ?>
<p style="color:#900"><?php echo Esc::h($e); ?></p>
<?php endforeach; ?>
<?php foreach ($notices as $n): ?>
<p style="color:#060"><?php echo Esc::h((string) $n); ?></p>
<?php endforeach; ?>
<h2>1. Requirements</h2>
<ul>
<?php foreach ($requirements as $c): ?>
<li><?php echo Esc::h($c['label']); ?> — <?php echo $c['ok'] ? 'OK' : 'MISSING'; ?>
<small><?php echo Esc::h($c['detail']); ?></small></li>
<?php endforeach; ?>
</ul>
<p>Installation lock: <?php echo $lockPresent ? 'present (installer disabled)' : 'absent'; ?> ·
.env: <?php echo $envExists ? 'present (never overwritten)' : 'absent'; ?></p>
<?php if (!$lockPresent && $reqOk): ?>
<h2>2. Database</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo Esc::h($csrf); ?>">
<input type="hidden" name="action" value="probe">
<p><label>Host <input name="db_host" value="127.0.0.1" required></label>
<label>Port <input name="db_port" value="3306" required></label></p>
<p><label>Database <input name="db_name" required></label>
<label>User <input name="db_user" required></label>
<label>Password <input type="password" name="db_pass"></label></p>
<p><button type="submit">Check connection (read-only)</button></p>
</form>
<h2>3. Write configuration</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo Esc::h($csrf); ?>">
<input type="hidden" name="action" value="write_env">
<p><label>Re-enter DB password to authorize .env write <input type="password" name="db_pass" required></label></p>
<p><button type="submit">Write .env (refuses if present)</button></p>
</form>
<h2>4. Migrate &amp; seed</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo Esc::h($csrf); ?>">
<input type="hidden" name="action" value="migrate">
<p><button type="submit">Run migrations</button></p>
</form>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo Esc::h($csrf); ?>">
<input type="hidden" name="action" value="seed">
<p><button type="submit">Apply seeds (fresh installs only)</button></p>
</form>
<h2>5. Administrator</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo Esc::h($csrf); ?>">
<input type="hidden" name="action" value="admin">
<p><label>Name <input name="admin_name" required></label>
<label>Email <input name="admin_email" type="email" required></label></p>
<p><label>Password (12+ chars) <input name="admin_password" type="password" required></label>
<label>Phone (optional) <input name="admin_phone"></label></p>
<p><button type="submit">Create Super Admin</button></p>
</form>
<?php endif; ?>
<?php if ($lockPresent): ?>
<h2>Disable installer</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo Esc::h($csrf); ?>">
<input type="hidden" name="action" value="disable">
<p><button type="submit">Rename setup.php to setup.disabled.php</button></p>
</form>
<?php endif; ?>
<form method="post" style="margin-top:2rem">
<input type="hidden" name="csrf" value="<?php echo Esc::h($csrf); ?>">
<input type="hidden" name="action" value="start">
<p><button type="submit">Start / restart setup session</button></p>
</form>
</body>
</html>
