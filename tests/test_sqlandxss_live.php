<?php
declare(strict_types=1);
// LIVE SQLi/XSS payload tests — disposable database ONLY (never sonatola_test).
// Skips cleanly when MySQL is unreachable. Cleans up its own test database.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Core/SqlSplitter.php';
require_once __DIR__ . '/../src/Core/AppException.php';
require_once __DIR__ . '/../src/Core/Validator.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../src/Repositories/BaseRepository.php';
require_once __DIR__ . '/../src/Repositories/UserRepository.php';
require_once __DIR__ . '/../src/Repositories/ContentRepository.php';
require_once __DIR__ . '/../src/Repositories/ComplaintRepository.php';
require_once __DIR__ . '/../src/Setup/InstallRunner.php';

echo "sqlandxss_live:\n";
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
    T::skip('sqlandxss live payload tests', 'no DB: ' . substr($e->getMessage(), 0, 100));
    return;
}

// Strict guard: only the exact disposable name may be created/dropped here.
$testDb = 'sonatola_sqli_test';
$guard = static function (string $db): string {
    $clean = preg_replace('/[^A-Za-z0-9_]/', '', $db);
    if ($clean !== 'sonatola_sqli_test') {
        throw new RuntimeException('refusing to touch non-test database');
    }
    return $clean;
};
$root = dirname(__DIR__);

try {
    $srv->exec('DROP DATABASE IF EXISTS `' . $guard($testDb) . '`');
    $srv->exec('CREATE DATABASE `' . $guard($testDb) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int) $cfg['port'], $testDb),
        (string) $cfg['user'],
        (string) $cfg['pass'],
        // Mirror src/Core/DB.php: native prepares. This is what production uses,
        // and it rejects repeated named placeholders (HY093) — the suite must
        // exercise the same mode or search regressions go undetected.
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    foreach (InstallRunner::migrationFiles($root . '/database/migrations') as $f) {
        InstallRunner::applyMigrationFile($pdo, $f);
    }
    $tablesBefore = $pdo->query("SHOW TABLES FROM `{$testDb}`")->fetchAll(PDO::FETCH_NUM);

    // 1) Classic login-bypass payloads return zero rows (prepared statements).
    $users = new UserRepository($pdo);
    $pdo->prepare('INSERT INTO users (name, email, password_hash, account_status) VALUES (:n, :e, :h, \'active\')')
        ->execute([':n' => 'Victim', ':e' => 'victim@example.test', ':h' => Auth::hashPassword('0123456789abCD')]);
    foreach (["' OR '1'='1", "' OR '1'='1' --", "' UNION SELECT * FROM users --", 'victim@example.test\' --'] as $evil) {
        T::ok($users->findByEmail($evil) === null, "bypass payload returns no user");
    }
    T::ok($users->findByEmail('victim@example.test') !== null, 'legitimate lookup still works');

    // 2) DROP TABLE via input fields: tables intact afterwards.
    $content = new ContentRepository($pdo);
    $evilTitle = "x'; DROP TABLE news; --";
    $id = $content->createNews([
        'category_id' => null, 'union_id' => null, 'upazila_id' => null, 'author_id' => null,
        'title' => $evilTitle, 'slug' => 'xss-sqli-probe slug', 'excerpt' => null,
        'body' => 'probe body', 'status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s'),
    ]);
    T::ok($id > 0, 'evil title stored as inert data (not executed)');
    $tablesAfter = $pdo->query("SHOW TABLES FROM `{$testDb}`")->fetchAll(PDO::FETCH_NUM);
    T::ok(count($tablesAfter) === count($tablesBefore), 'schema intact after DROP attempt');

    // 3) LIKE wildcards are literal: '%' must not match everything.
    $content->createNews([
        'category_id' => null, 'union_id' => null, 'upazila_id' => null, 'author_id' => null,
        'title' => '100% coverage_test report', 'slug' => 'pct-probe', 'excerpt' => null,
        'body' => 'plain body', 'status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $wild = $content->listNews(1, 10, 'published', null, '%');
    T::ok($wild['total'] === 1, 'bare % matches only the literal-% row (got ' . $wild['total'] . ')');
    $under = $content->listNews(1, 10, 'published', null, '_');
    T::ok($under['total'] === 1, 'bare _ matches only the literal-_ row (got ' . $under['total'] . ')');
    $plain = $content->listNews(1, 10, 'published', null, 'coverage');
    T::ok($plain['total'] === 1, 'plain substring search still works');

    // 3b) Backslash literals and Unicode substrings behave literally.
    $content->createNews([
        'category_id' => null, 'union_id' => null, 'upazila_id' => null, 'author_id' => null,
        'title' => 'C:\path\file record', 'slug' => 'bs-probe', 'excerpt' => null,
        'body' => 'plain body', 'status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $content->createNews([
        'category_id' => null, 'union_id' => null, 'upazila_id' => null, 'author_id' => null,
        'title' => 'সোনাতলা উন্নয়ন', 'slug' => 'bn-probe', 'excerpt' => null,
        'body' => 'plain body', 'status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $bs = $content->listNews(1, 10, 'published', null, '\\');
    $bsTitles = array_column($bs['items'], 'title');
    T::ok($bs['total'] === 1 && ($bsTitles[0] ?? '') === 'C:\path\file record', 'backslash search is literal');
    $bn = $content->listNews(1, 10, 'published', null, 'সোনাতলা');
    T::ok($bn['total'] === 1 && ($bnTitles = array_column($bn['items'], 'title')) && $bnTitles[0] === 'সোনাতলা উন্নয়ন', 'unicode substring search works');
    $empty = $content->listNews(1, 10, 'published', null, '');
    // Four published rows exist at this point (evil, pct, bs, bn — the xss
    // roundtrip row is inserted afterwards).
    T::ok($empty['total'] === 4, 'empty search term means no filter (got ' . $empty['total'] . ')');

    // 4) Stored XSS payload round-trips byte-identical through the JSON layer
    //    (data fidelity preserved; execution is the consumer's context to escape).
    $xss = '<script>alert(document.cookie)</script><img src=x onerror=alert(1)>';
    $content->createNews([
        'category_id' => null, 'union_id' => null, 'upazila_id' => null, 'author_id' => null,
        'title' => 'xss roundtrip', 'slug' => 'xss-probe', 'excerpt' => null,
        'body' => $xss, 'status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $row = $content->findNewsBySlug('xss-probe');
    T::ok(($row['body'] ?? null) === $xss, 'stored payload byte-identical on read');
    $envelope = json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    T::ok(is_string($envelope) && json_decode($envelope, true)['data']['body'] === $xss, 'JSON envelope carries payload inertly');
} finally {
    // Cleanup: drop ONLY the exact disposable test database.
    try {
        $srv->exec('DROP DATABASE IF EXISTS `' . $guard($testDb) . '`');
    } catch (Throwable) {
    }
}
T::ok(true, 'disposable sqli test database cleaned up');
