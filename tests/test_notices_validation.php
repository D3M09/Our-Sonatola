<?php
declare(strict_types=1);
// Expired notices hidden from public; invalid priority/status rejected with 422-style validation.
require_once __DIR__ . '/../src/Core/Validator.php';
require_once __DIR__ . '/../src/Repositories/BaseRepository.php';
require_once __DIR__ . '/../src/Repositories/ContentRepository.php';

echo "notices_expiry:\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE notices (id INTEGER PRIMARY KEY AUTOINCREMENT, union_id INTEGER, upazila_id INTEGER, author_id INTEGER, title TEXT, body TEXT, status TEXT DEFAULT "draft", published_at TEXT, expires_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$now = gmdate('Y-m-d H:i:s');
$past = gmdate('Y-m-d H:i:s', time() - 3600);
$future = gmdate('Y-m-d H:i:s', time() + 3600);
$ins = $pdo->prepare('INSERT INTO notices (title, body, status, published_at, expires_at) VALUES (:t,:b,:s,:p,:e)');
$ins->execute([':t' => 'active', ':b' => 'b', ':s' => 'published', ':p' => $now, ':e' => null]);
$ins->execute([':t' => 'expired', ':b' => 'b', ':s' => 'published', ':p' => $now, ':e' => $past]);
$ins->execute([':t' => 'future', ':b' => 'b', ':s' => 'published', ':p' => $now, ':e' => $future]);
$ins->execute([':t' => 'draft', ':b' => 'b', ':s' => 'draft', ':p' => null, ':e' => null]);

$repo = new ContentRepository($pdo);
$pub = $repo->listNotices(1, 10, 'published', null, null, true);
$titles = array_column($pub['items'], 'title');
T::ok(in_array('active', $titles, true) && in_array('future', $titles, true), 'active + future visible');
T::ok(!in_array('expired', $titles, true) && !in_array('draft', $titles, true), 'expired + draft hidden from public');

$admin = $repo->listNotices(1, 10, null, null, null, false);
T::ok(count($admin['items']) === 4, 'admin sees all including expired');

echo "complaint_validation:\n";
$errs = Validator::validate(['priority' => 'hacked'], ['priority' => 'in:low,normal,high,urgent']);
T::ok(isset($errs['priority']), 'invalid priority rejected');
$errs = Validator::validate(['priority' => 'high'], ['priority' => 'in:low,normal,high,urgent']);
T::ok(!isset($errs['priority']), 'valid priority accepted');

// Controller must validate status filter instead of leaking DB errors
$src = (string) file_get_contents(__DIR__ . '/../src/Controllers/ComplaintController.php');
T::ok(str_contains($src, 'Invalid status filter'), 'complaint status filter validated');
// Service must validate priority
$svc = (string) file_get_contents(__DIR__ . '/../src/Services/ComplaintService.php');
T::ok(str_contains($svc, 'in:low,normal,high,urgent'), 'complaint priority validated');
