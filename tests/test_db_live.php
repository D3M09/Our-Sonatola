<?php
declare(strict_types=1);
// Live DB tests — SKIPPED when MySQL unavailable (per spec: never fabricate results)
require_once __DIR__ . '/../config/config.php';
Config::load(dirname(__DIR__));

echo "db_live:\n";
$cfg = Config::db();
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int) $cfg['port'], $cfg['charset'] ?? 'utf8mb4'),
        (string) $cfg['user'],
        (string) $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('USE `' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $cfg['name']) . '`');
    T::ok(true, 'db connectivity');

    // Prepared statement check (SQLi resistance live)
    $evil = "' OR '1'='1";
    $st = $pdo->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
    $st->execute([':e' => $evil]);
    $rows = $st->fetchAll();
    T::ok($rows === [], 'sqli login bypass returns no rows (prepared)');

    // Migration bookkeeping exists
    $v = $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    T::ok($v !== false, 'schema_migrations readable');
} catch (Throwable $e) {
    T::skip('db connectivity', 'no DB: ' . substr($e->getMessage(), 0, 120));
    T::skip('sqli live check', 'no DB');
    T::skip('migrations live check', 'no DB');
}
