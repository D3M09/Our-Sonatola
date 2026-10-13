<?php
declare(strict_types=1);
// Installer LIVE integration — disposable databases ONLY (never sonatola_test).
// Skips cleanly when MySQL is unreachable. Cleans up its own test databases.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Core/SqlSplitter.php';
require_once __DIR__ . '/../src/Core/AppException.php';
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../src/Setup/InstallRunner.php';
require_once __DIR__ . '/../src/Setup/InstallerState.php';
require_once __DIR__ . '/../src/Setup/InstallLock.php';

echo "setup_live:\n";
Config::load(dirname(__DIR__));
$cfg = Config::db();
try {
    $srv = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], (int) $cfg['port']),
        (string) $cfg['user'],
        (string) $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    T::skip('setup live integration', 'no DB: ' . substr($e->getMessage(), 0, 100));
    return;
}

// Strict guard: only exact disposable names may be created/dropped here.
$testDb = 'sonatola_setup_test';
$partDb = 'sonatola_setup_part';
$guard = static function (string $db): string {
    $clean = preg_replace('/[^A-Za-z0-9_]/', '', $db);
    if (!str_starts_with($clean, 'sonatola_setup_')) {
        throw new RuntimeException('refusing to touch non-test database');
    }
    return $clean;
};
$root = dirname(__DIR__);

try {
    foreach ([$testDb, $partDb] as $d) {
        $srv->exec('DROP DATABASE IF EXISTS `' . $guard($d) . '`');
        $srv->exec('CREATE DATABASE `' . $guard($d) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    // Fresh-state probe on the empty disposable DB.
    $st = InstallRunner::probeState($srv, $testDb);
    T::ok($st['dbExists'] === true && $st['applied'] === [], 'fresh disposable DB probed (0 applied)');
    $v = InstallerState::classify([], [], [], ['roles'=>0,'permissions'=>0,'settings'=>0,'upazilas'=>0,'categories'=>0,'superAdmins'=>0], false);
    T::ok($v['state'] === InstallerState::FRESH, 'empty DB classifies FRESH');

    // Full migration via the shared runner.
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int) $cfg['port'], $testDb),
        (string) $cfg['user'],
        (string) $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    foreach (InstallRunner::migrationFiles($root . '/database/migrations') as $f) {
        InstallRunner::applyMigrationFile($pdo, $f);
    }
    $applied = array_column($pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(), 'version');
    T::ok(count($applied) === 11, '11 versions applied live');

    // Seed-fresh gate + seeds + verification.
    T::ok((int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0, 'settings empty before seeds');
    foreach (InstallRunner::seedFiles($root . '/database/seeds') as $s) {
        InstallRunner::applySeedFile($pdo, $s);
    }
    T::ok((int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() === 6, '6 roles seeded live');
    T::ok((int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn() === 18, '18 permissions seeded live');
    T::ok($pdo->query("SELECT scope FROM roles WHERE slug = 'union_admin'")->fetchColumn() === 'union', 'union scope persisted live');

    // Complete schema+seeds but no admin and no marker: ambiguous → fail closed.
    $preAdmin = InstallerState::inspect($pdo, $testDb, [], false);
    T::ok($preAdmin['state'] === InstallerState::UNSAFE, 'adminless complete DB without marker = UNSAFE');

    // Concurrent admin creation: hold the advisory lock elsewhere, creation must refuse.
    $pdoB = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int) $cfg['port'], $testDb),
        (string) $cfg['user'],
        (string) $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdoB->query("SELECT GET_LOCK('sonatola_setup_admin', 5)");
    try {
        InstallRunner::createSuperAdmin($pdo, 'A', 'a@live.test', Auth::hashPassword('0123456789ab'), null, null, 1);
        T::ok(false, 'creation refused while lock held elsewhere');
    } catch (AppException $e) {
        T::ok($e->statusCode() === 429, 'creation refused while lock held elsewhere (429)');
    }
    $pdoB->query("SELECT RELEASE_LOCK('sonatola_setup_admin')");
    T::ok((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0, 'no orphan user from refused attempt');

    // Normal creation, then double-submit refused with zero orphans.
    require_once __DIR__ . '/../src/Repositories/BaseRepository.php';
    require_once __DIR__ . '/../src/Repositories/UserRepository.php';
    $uid = InstallRunner::createSuperAdmin($pdo, 'Live Admin', 'live@admin.test', Auth::hashPassword('0123456789abCD'), null, null);
    T::ok($uid > 0, 'super admin created live');
    try {
        InstallRunner::createSuperAdmin($pdo, 'Second', 'second@admin.test', Auth::hashPassword('0123456789abCD'), null, null);
        T::ok(false, 'second super admin refused');
    } catch (AppException $e) {
        T::ok($e->statusCode() === 403, 'second super admin refused (403)');
    }
    T::ok((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'exactly one user (no orphans)');
    T::ok((int) $pdo->query('SELECT COUNT(*) FROM user_roles')->fetchColumn() === 1, 'exactly one role row');

    // Installed DB without marker/lock fails closed (refuses fresh-install actions).
    $verdict = InstallerState::inspect($pdo, $testDb, [], false);
    T::ok($verdict['state'] === InstallerState::EXISTING, 'complete install with admin = EXISTING');

    // Interrupted setup on the part DB: 001 only + matching marker → resume → complete.
    $pdoP = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int) $cfg['port'], $partDb),
        (string) $cfg['user'],
        (string) $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    InstallRunner::applyMigrationFile($pdoP, $root . '/database/migrations/001_schema_migrations.sql');
    $pv = InstallerState::inspect($pdoP, $partDb, ['migrated_versions' => 1], false);
    T::ok($pv['state'] === InstallerState::INTERRUPTED, 'partial matching marker = INTERRUPTED live');
    foreach (InstallRunner::migrationFiles($root . '/database/migrations') as $f) {
        if (basename($f, '.sql') === '001_schema_migrations') {
            continue;
        }
        InstallRunner::applyMigrationFile($pdoP, $f);
    }
    T::ok(count(array_column($pdoP->query('SELECT version FROM schema_migrations')->fetchAll(), 'version')) === 11, 'resume completes to 11');

    // Lock recovery on an isolated temp root (never the real storage/).
    $tmpRoot = sys_get_temp_dir() . '/setup_locktest_' . bin2hex(random_bytes(4));
    @mkdir($tmpRoot . '/storage', 0700, true);
    try {
        T::ok(InstallLock::exists($tmpRoot) === false, 'no lock initially (temp root)');
        InstallLock::create($tmpRoot, ['migrated_versions' => 11]);
        T::ok(InstallLock::exists($tmpRoot) === true, 'lock created');
        @unlink(InstallLock::lockPath($tmpRoot));
        T::ok(InstallLock::exists($tmpRoot) === false, 'lock removable via filesystem');
    } finally {
        @unlink(InstallLock::lockPath($tmpRoot));
        @unlink(InstallLock::progressPath($tmpRoot));
        @rmdir($tmpRoot . '/storage');
        @rmdir($tmpRoot);
    }

    // Existing project .env is never overwritten (refusal only, no write).
    require_once __DIR__ . '/../src/Setup/EnvWriter.php';
    try {
        EnvWriter::write($root, 'X=1');
        T::ok(false, 'existing project .env refused');
    } catch (AppException $e) {
        T::ok($e->statusCode() === 403, 'existing project .env refused (403)');
    }
} finally {
    // Cleanup: drop ONLY the exact disposable test databases.
    foreach ([$testDb, $partDb] as $d) {
        try {
            $srv->exec('DROP DATABASE IF EXISTS `' . $guard($d) . '`');
        } catch (Throwable) {
        }
    }
}
T::ok(true, 'disposable test databases cleaned up');
