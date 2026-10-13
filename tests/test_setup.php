<?php
declare(strict_types=1);
// Installer unit tests — no database contact.
require_once __DIR__ . '/../src/Core/AppException.php';
require_once __DIR__ . '/../src/Core/SqlSplitter.php';
require_once __DIR__ . '/../src/Setup/Requirements.php';
require_once __DIR__ . '/../src/Setup/SetupSession.php';
require_once __DIR__ . '/../src/Setup/InstallerState.php';
require_once __DIR__ . '/../src/Setup/EnvWriter.php';
require_once __DIR__ . '/../src/Setup/InstallLock.php';
require_once __DIR__ . '/../src/Setup/InstallRunner.php';

echo "setup_requirements:\n";
$checks = SetupRequirements::check(dirname(__DIR__));
T::ok(count($checks) >= 7, 'requirements list complete');
$labels = array_column($checks, 'label');
T::ok(in_array('PHP version >= 8.1', $labels, true), 'php version check present');
T::ok(in_array('PHP extension pdo_mysql', $labels, true), 'pdo_mysql check present');
T::ok(SetupRequirements::allOk($checks) === true, 'all requirements ok here');
T::ok(SetupRequirements::allOk([['label' => 'x', 'ok' => false, 'detail' => 'y']]) === false, 'allOk detects failure');

echo "setup_session:\n";
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
SetupSession::init();
T::ok(SetupSession::touch() === true, 'fresh wizard session valid');
T::ok(strlen(SetupSession::csrfToken()) === 64, 'csrf token issued');
T::ok(SetupSession::validateCsrf(SetupSession::csrfToken()) === true, 'csrf valid accepted');
T::ok(SetupSession::validateCsrf('bogus') === false, 'csrf bogus rejected');
T::ok(SetupSession::validateCsrf(null) === false, 'csrf null rejected');
// Password must never be persistable via setDbFacts
SetupSession::setDbFacts(['host' => 'h', 'port' => '3306', 'name' => 'd', 'user' => 'u', 'charset' => 'utf8mb4', 'pass' => 'SECRET']);
$s = SetupSession::get();
T::ok(!array_key_exists('pass', $s['db'] ?? []), 'password never stored in session');
// Idle expiry wipes state
$_SESSION['sonatola_setup']['last_activity'] = time() - 601;
T::ok(SetupSession::touch() === false, 'idle-expired session rejected');
T::ok(SetupSession::get() === [], 'expired state wiped');
SetupSession::clear();

echo "setup_state:\n";
$full = ['roles' => 6, 'permissions' => 18, 'settings' => 6, 'upazilas' => 1, 'categories' => 3, 'superAdmins' => 1];
$v11 = ['001_schema_migrations','002_locations','003_roles_permissions','004_users','005_content','006_services','007_complaints','008_applications','009_media_notifications','010_tokens_audit_settings','011_roles_scope'];
$r = InstallerState::classify([], [], [], ['roles'=>0,'permissions'=>0,'settings'=>0,'upazilas'=>0,'categories'=>0,'superAdmins'=>0], false);
T::ok($r['state'] === InstallerState::FRESH, 'zero tables = fresh');
$r = InstallerState::classify(['users'], [], [], ['roles'=>0,'permissions'=>0,'settings'=>0,'upazilas'=>0,'categories'=>0,'superAdmins'=>0], false);
T::ok($r['state'] === InstallerState::UNSAFE, 'tables without marker/versions = unsafe (never inferred empty)');
$r = InstallerState::classify(['users','schema_migrations'], ['001_schema_migrations'], ['migrated_versions' => 1], ['roles'=>0,'permissions'=>0,'settings'=>0,'upazilas'=>0,'categories'=>0,'superAdmins'=>0], false);
T::ok($r['state'] === InstallerState::INTERRUPTED, 'partial matching marker = interrupted');
$r = InstallerState::classify(['users','schema_migrations'], ['001_schema_migrations'], ['migrated_versions' => 5], ['roles'=>0,'permissions'=>0,'settings'=>0,'upazilas'=>0,'categories'=>0,'superAdmins'=>0], false);
T::ok($r['state'] === InstallerState::UNSAFE, 'marker/version mismatch = unsafe');
$r = InstallerState::classify(['users'], $v11, [], $full, false);
T::ok($r['state'] === InstallerState::EXISTING, 'complete install = existing');
$r = InstallerState::classify([], [], [], ['roles'=>0,'permissions'=>0,'settings'=>0,'upazilas'=>0,'categories'=>0,'superAdmins'=>0], true);
T::ok($r['state'] === InstallerState::EXISTING, 'lock present = existing');

echo "setup_envwriter:\n";
$rendered = EnvWriter::render('127.0.0.1', '3306', 'sonatola_test', 'root', 's3cret!');
T::ok(str_contains($rendered, 'DB_NAME=sonatola_test') && str_contains($rendered, 'PASSWORD_RESET_ENABLED=false'), 'render keeps key order/values');
T::ok(str_contains($rendered, 'APP_DEBUG=false'), 'render pins safe defaults');
$tmp = sys_get_temp_dir() . '/setup_envtest_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);
try {
    $res = EnvWriter::write($tmp, $rendered);
    T::ok(is_file($tmp . '/.env'), '.env written atomically');
    T::ok(is_bool($res['chmod_ok']), 'chmod result reported (best-effort)');
    try {
        EnvWriter::write($tmp, $rendered);
        T::ok(false, 'existing .env never overwritten');
    } catch (AppException $e) {
        T::ok($e->statusCode() === 403, 'existing .env refused (403)');
    }
    T::ok((string) @file_get_contents($tmp . '/.env') === $rendered, 'written content exact');
} finally {
    @unlink($tmp . '/.env');
    @rmdir($tmp);
}

echo "setup_runner_helpers:\n";
T::ok(count(InstallRunner::migrationFiles(dirname(__DIR__) . '/database/migrations')) === 11, '11 migration files discovered');
T::ok(count(InstallRunner::seedFiles(dirname(__DIR__) . '/database/seeds')) === 4, '4 seed files discovered');
T::ok(InstallRunner::pendingBasenames(['/x/001_a.sql', '/x/002_b.sql'], ['001_a']) === ['002_b'], 'pending computation');
T::ok(InstallRunner::containsDestructiveDrop('DROP TABLE x') === true, 'drop detected');
T::ok(InstallRunner::containsDestructiveDrop('CREATE TABLE x (id INT)') === false, 'create is not destructive');

echo "setup_dispatcher_guards:\n";
$disp = (string) file_get_contents(__DIR__ . '/../public/setup.php');
T::ok(str_contains($disp, "(\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'"), 'single POST gate');
T::ok(substr_count($disp, '->exec(') === 0, 'dispatcher has no direct SQL exec (all via InstallRunner)');
T::ok(str_contains($disp, 'unset($_POST[') || str_contains($disp, "unset(\$pass)"), 'passwords unset after use');
T::ok(!preg_match('/\$_GET\[.action.\]/', $disp), 'no GET-driven actions');
T::ok(str_contains($disp, 'migrated_versions') && str_contains($disp, 'seeded'), 'admin gated on wizard marker');
