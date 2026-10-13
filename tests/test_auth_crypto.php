<?php
declare(strict_types=1);
// Auth crypto + escaping + CSRF (no DB)
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../src/Core/Esc.php';
require_once __DIR__ . '/../src/Core/Csrf.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/AppException.php';

echo "auth_crypto:\n";
$h = Auth::hashPassword('correct-horse-123');
T::ok(Auth::verifyPassword('correct-horse-123', $h), 'password verify ok');
T::ok(!Auth::verifyPassword('wrong', $h), 'password wrong rejected');
T::ok(!str_contains($h, 'correct-horse'), 'hash never contains plaintext');

$t = Auth::newToken();
T::ok(strlen($t['token']) === 64 && $t['hash'] === hash('sha256', $t['token']), 'token sha256 mapping');
T::ok(!str_contains($t['hash'], $t['token']), 'stored hash differs from raw');

echo "escaping:\n";
T::ok(Esc::h('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'html escaping');

echo "csrf:\n";
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$_SESSION = [];
$tok = Csrf::token();
T::ok(is_string($tok) && strlen($tok) === 64, 'csrf token issued');
T::ok(Csrf::validate($tok), 'csrf valid accepted');
T::ok(!Csrf::validate('bad'), 'csrf bad rejected');
T::ok(!Csrf::validate(null), 'csrf null rejected');
