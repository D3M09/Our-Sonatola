<?php
declare(strict_types=1);
// --dry-run must perform zero database writes (static guard + live snapshot check).
require_once __DIR__ . '/../config/config.php';

echo "dryrun_readonly:\n";

// 1) Static guard: the dry-run branch of migrate.php must contain no writes.
$src = (string) file_get_contents(__DIR__ . '/../database/migrate.php');
$start = strpos($src, 'if ($dryRun) {');
$end = strpos($src, "exit(0);", $start !== false ? $start : 0);
T::ok($start !== false && $end !== false && $end > $start, 'dry-run branch found');
if ($start !== false && $end !== false) {
    $branch = substr($src, $start, $end - $start);
    T::ok(!preg_match('/->exec\s*\(/i', $branch), 'dry-run branch has no ->exec()');
    T::ok(!preg_match('/\b(INSERT|CREATE|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE)\b/i', $branch), 'dry-run branch has no DDL/DML keywords');
    // Probes live in InstallRunner::probeState (single shared implementation also
    // used by setup.php); the branch must only delegate to it.
    T::ok(str_contains($branch, 'InstallRunner::probeState'), 'dry-run delegates to shared probe');
    $probeSrc = (string) file_get_contents(__DIR__ . '/../src/Setup/InstallRunner.php');
    $pStart = strpos($probeSrc, 'function probeState');
    $pEnd = strpos($probeSrc, 'function pendingBasenames', $pStart !== false ? $pStart : 0);
    $probeFn = ($pStart !== false && $pEnd !== false) ? substr($probeSrc, $pStart, $pEnd - $pStart) : '';
    T::ok($probeFn !== '' && str_contains($probeFn, 'information_schema'), 'probe reads information_schema only');
    T::ok(!preg_match('/->exec\s*\(/i', $probeFn), 'probe performs no ->exec()');
    T::ok(!preg_match('/\b(INSERT|CREATE|UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE)\b/i', $probeFn), 'probe has no DDL/DML keywords');
}

// 2) Live check against the configured (disposable test) DB; skip when unreachable.
//    Only SELECT/SHOW reads are used to snapshot state around the script run.
Config::load(dirname(__DIR__));
$cfg = Config::db();
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int) $cfg['port'], $cfg['charset'] ?? 'utf8mb4'),
        (string) $cfg['user'],
        (string) $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $dbName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $cfg['name']);
    $tablesBefore = $pdo->query("SHOW TABLES FROM `{$dbName}`")->fetchAll(PDO::FETCH_NUM);
    $countBefore = count($tablesBefore);
    // Applied versions before (empty when bookkeeping absent) — dry-run must not change these.
    $appliedBefore = [];
    try {
        $appliedBefore = array_column(
            $pdo->query("SELECT version FROM `{$dbName}`.schema_migrations ORDER BY version")->fetchAll(),
            'version'
        );
    } catch (Throwable) {
        $appliedBefore = [];
    }
    $projRoot = dirname(__DIR__);
    $allFiles = array_map(
        static fn($f) => basename((string) $f, '.sql'),
        glob($projRoot . '/database/migrations/*.sql') ?: []
    );
    sort($allFiles);
    $expectPending = array_values(array_diff($allFiles, $appliedBefore));
} catch (Throwable $e) {
    T::skip('dry-run live no-write check', 'no DB: ' . substr($e->getMessage(), 0, 100));
    return;
}

$php = PHP_BINARY;
$root = dirname(__DIR__);
foreach ([['--dry-run'], ['--dry-run', '--seeds']] as $args) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/database/migrate.php') . ' ' . implode(' ', $args) . ' 2>&1';
    $out = [];
    $code = -1;
    exec('cd ' . escapeshellarg($root) . ' && ' . $cmd, $out, $code);
    $text = implode("\n", $out);
    T::ok($code === 0, 'dry-run exit 0 (' . implode(' ', $args) . ')');
    T::ok(str_contains($text, '[DRY-RUN]') && str_contains($text, 'database untouched'), 'dry-run output marked read-only');
    // Pending list in output must exactly match live applied state (works empty or migrated).
    $listsAllPending = true;
    foreach ($expectPending as $v) {
        if (!str_contains($text, $v . '.sql')) {
            $listsAllPending = false;
            break;
        }
    }
    $listsNoneApplied = true;
    foreach ($appliedBefore as $v) {
        if (str_contains($text, $v . '.sql')) {
            $listsNoneApplied = false;
            break;
        }
    }
    T::ok($listsAllPending && $listsNoneApplied, 'dry-run pending list matches live state (' . count($expectPending) . ' pending)');
}
// (The second run above already covers --dry-run --seeds: seeds listed, not executed.)

// 3) State must be identical: same tables, same applied versions.
try {
    $tablesAfter = $pdo->query("SHOW TABLES FROM `{$dbName}`")->fetchAll(PDO::FETCH_NUM);
    T::ok(count($tablesAfter) === $countBefore, "table count unchanged ({$countBefore})");
    $appliedAfter = [];
    try {
        $appliedAfter = array_column(
            $pdo->query("SELECT version FROM `{$dbName}`.schema_migrations ORDER BY version")->fetchAll(),
            'version'
        );
    } catch (Throwable) {
        $appliedAfter = [];
    }
    T::ok($appliedAfter === $appliedBefore, 'applied versions unchanged by dry-run (' . count($appliedBefore) . ')');
} catch (Throwable $e) {
    T::ok(false, 'post dry-run snapshot readable: ' . substr($e->getMessage(), 0, 100));
}
