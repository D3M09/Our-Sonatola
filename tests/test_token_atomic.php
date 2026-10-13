<?php
declare(strict_types=1);
// Atomic single-use token consumption (SQLite in-memory, portable PHP-now comparison)
require_once __DIR__ . '/../src/Core/Auth.php';

echo "token_atomic:\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, type TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

$raw = Auth::issueToken($pdo, 1, Auth::TYPE_RESET, 3600);
T::ok(strlen($raw) === 64, 'reset token issued');

$first = Auth::consumeToken($pdo, $raw, Auth::TYPE_RESET);
T::ok($first !== null && (int) $first['user_id'] === 1, 'first consume succeeds');

$second = Auth::consumeToken($pdo, $raw, Auth::TYPE_RESET);
T::ok($second === null, 'second consume rejected (single-use)');

// Wrong type must not consume
$raw2 = Auth::issueToken($pdo, 2, Auth::TYPE_RESET, 3600);
T::ok(Auth::consumeToken($pdo, $raw2, Auth::TYPE_API) === null, 'wrong type rejected');
// Correct type still works after wrong-type attempt
T::ok(Auth::consumeToken($pdo, $raw2, Auth::TYPE_RESET) !== null, 'correct type consumes after wrong-type attempt');

// Expired token rejected
$expiredRaw = bin2hex(random_bytes(32));
$st = $pdo->prepare("INSERT INTO auth_tokens (user_id, token_hash, type, expires_at) VALUES (3, :h, 'password_reset', :e)");
$st->execute([':h' => hash('sha256', $expiredRaw), ':e' => gmdate('Y-m-d H:i:s', time() - 10)]);
T::ok(Auth::consumeToken($pdo, $expiredRaw, Auth::TYPE_RESET) === null, 'expired token rejected');

// Revoke helper marks sessions used
$api = Auth::issueToken($pdo, 5, Auth::TYPE_API, 86400);
Auth::revokeUserApiSessions($pdo, 5);
T::ok(Auth::consumeToken($pdo, $api, Auth::TYPE_API) === null, 'revoked api session cannot be consumed');
