<?php
declare(strict_types=1);

/**
 * Safe migration runner (CLI only).
 * Usage:
 *   php database/migrate.php [--dry-run] [--seeds]
 * Never runs destructive statements automatically; aborts on DROP/DROP TABLE without --allow-destructive.
 *
 * Execution model: each .sql file is split into individual statements with
 * SqlSplitter (quote/comment aware) and executed statement-by-statement via PDO.
 * Ordering is filename-sorted; bookkeeping in schema_migrations ensures rerun safety.
 * Shared logic lives in src/Setup/InstallRunner.php (also used by public/setup.php).
 *
 * --dry-run is genuinely read-only: it connects, probes existence/version status
 * with SELECTs on information_schema only, prints pending files with statement
 * counts, and exits before any CREATE/USE/DDL/DML. When the database or the
 * bookkeeping table is absent, that status is REPORTED, not created.
 *
 * IMPORTANT: MySQL/MariaDB DDL (CREATE/ALTER) causes an implicit commit and
 * cannot be rolled back transactionally. This runner does NOT wrap DDL in a
 * transaction and does NOT claim transactional rollback. On failure it stops at
 * the failing statement; restore from the pre-migration backup to recover.
 */

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/src/Core/SqlSplitter.php';
require_once $root . '/src/Setup/InstallRunner.php';
Config::load($root);

$options = getopt('', ['dry-run', 'seeds', 'allow-destructive']);
$dryRun = isset($options['dry-run']);
$withSeeds = isset($options['seeds']);
$allowDestructive = isset($options['allow-destructive']);

$cfg = Config::db();
$dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int) $cfg['port'], $cfg['charset'] ?? 'utf8mb4');

try {
    $pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$migrationDir = __DIR__ . '/migrations';
$files = InstallRunner::migrationFiles($migrationDir);
$dbName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $cfg['name']);

// --- Genuinely read-only dry run: no CREATE/USE/DDL/DML of any kind. ---
// Pending status is derived from read-only information_schema probes; when the
// database or the bookkeeping table does not exist yet, that is REPORTED
// (all files pending) instead of being created.
if ($dryRun) {
    try {
        $state = InstallRunner::probeState($pdo, $dbName);
    } catch (Throwable $e) {
        fwrite(STDERR, 'DRY-RUN probe failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    $applied = $state['applied'];
    $pending = [];
    foreach ($files as $f) {
        $v = basename($f, '.sql');
        if (!in_array($v, $applied, true)) {
            $pending[] = $f;
        }
    }

    echo '[DRY-RUN] ' . count($pending) . ' pending migration(s) (no writes performed):' . PHP_EOL;
    foreach ($pending as $f) {
        $n = count(InstallRunner::splitFile($f));
        echo ' - ' . basename($f) . " ({$n} statement(s))" . PHP_EOL;
    }
    if (!$state['dbExists']) {
        echo "Note: database `{$dbName}` does not exist yet; all files listed above would apply on a real run." . PHP_EOL;
    } elseif (!$state['bookkeepingExists']) {
        echo 'Note: schema_migrations table absent; versions unknown, all files listed above would apply.' . PHP_EOL;
    } else {
        echo count($applied) . ' version(s) already applied.' . PHP_EOL;
    }
    if ($withSeeds) {
        $seeds = InstallRunner::seedFiles(__DIR__ . '/seeds');
        echo count($seeds) . ' seed file(s) (not executed in dry-run):' . PHP_EOL;
        foreach ($seeds as $s) {
            $n = count(InstallRunner::splitFile($s));
            echo ' - ' . basename($s) . " ({$n} statement(s))" . PHP_EOL;
        }
    }
    echo 'Done. (dry-run: database untouched)' . PHP_EOL;
    exit(0);
}

// --- Real runs only (writes begin here) ---
// Ensure database exists.
InstallRunner::ensureDatabase($pdo, $dbName);

// Ensure bookkeeping table exists first (single statement file).
// Pure DDL here (no version row); 001 is recorded by the normal apply loop below.
$first = $migrationDir . '/001_schema_migrations.sql';
if (is_file($first)) {
    foreach (InstallRunner::splitFile($first) as $stmt) {
        $pdo->exec($stmt);
    }
}

$applied = [];
try {
    $applied = array_column($pdo->query('SELECT version FROM schema_migrations')->fetchAll(), 'version');
} catch (Throwable) {
}

$pending = [];
foreach ($files as $f) {
    $v = basename($f, '.sql');
    if (!in_array($v, $applied, true)) {
        $pending[] = $f;
    }
}

if ($pending === []) {
    echo "Nothing to migrate. All versions applied." . PHP_EOL;
} else {
    echo count($pending) . " pending migration(s):" . PHP_EOL;
    foreach ($pending as $f) {
        echo " - " . basename($f) . PHP_EOL;
    }
}

foreach ($pending as $f) {
    $sql = (string) file_get_contents($f);
    // Destructive guard
    if (!$allowDestructive && InstallRunner::containsDestructiveDrop($sql)) {
        fwrite(STDERR, "ABORT: " . basename($f) . " contains DROP. Re-run with --allow-destructive after manual review." . PHP_EOL);
        exit(1);
    }
    $version = basename($f, '.sql');
    echo "Applying {$version}... ";
    try {
        // NOTE: No transaction around DDL — MySQL/MariaDB implicitly commits
        // CREATE/ALTER. Each statement runs individually; on failure we stop
        // and leave prior statements applied (restore from backup to recover).
        $n = InstallRunner::applyMigrationFile($pdo, $f);
        echo "OK ({$n} statement(s))" . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, PHP_EOL . "FAILED {$version}: " . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, "DDL is not transactional — restore from pre-migration backup if needed." . PHP_EOL);
        exit(1);
    }
}

if ($withSeeds) {
    $seeds = InstallRunner::seedFiles(__DIR__ . '/seeds');
    foreach ($seeds as $s) {
        echo "Seeding " . basename($s) . "... ";
        try {
            InstallRunner::applySeedFile($pdo, $s);
            echo "OK" . PHP_EOL;
        } catch (Throwable $e) {
            fwrite(STDERR, PHP_EOL . "SEED FAILED " . basename($s) . ": " . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }
}

echo "Done." . PHP_EOL;
