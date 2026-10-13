<?php
declare(strict_types=1);
// CSRF on complaint submit: session-cookie POST without token must 403; Bearer exempt.
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/AppException.php';
require_once __DIR__ . '/../src/Core/Csrf.php';

echo "csrf_submit:\n";
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Controller source must enforce CSRF in submit()
$src = (string) file_get_contents(__DIR__ . '/../src/Controllers/ComplaintController.php');
$submitBody = substr($src, (int) strpos($src, 'function submit'));
$submitBody = substr($submitBody, 0, (int) strpos($submitBody, 'function mine'));
T::ok(str_contains($submitBody, 'Csrf::requireValid'), 'submit() calls Csrf::requireValid');

// Session-authed POST without token -> 403
$_SESSION = ['user_id' => 7, 'csrf_token' => 'secret-token'];
$req = new Request('POST', '/api/v1/complaints', [], ['subject' => 'x'], []);
try {
    Csrf::requireValid($req);
    T::ok(false, 'session POST without token rejected');
} catch (AppException $e) {
    T::ok($e->statusCode() === 403, 'session POST without token rejected (403)');
}

// Session-authed POST with valid header token -> pass
$req2 = new Request('POST', '/api/v1/complaints', [], ['subject' => 'x'], ['x-csrf-token' => 'secret-token']);
try {
    Csrf::requireValid($req2);
    T::ok(true, 'session POST with token allowed');
} catch (Throwable) {
    T::ok(false, 'session POST with token allowed');
}

// No session (Bearer API client) -> exempt even without token
$_SESSION = [];
$req3 = new Request('POST', '/api/v1/complaints', [], ['subject' => 'x'], []);
try {
    Csrf::requireValid($req3);
    T::ok(true, 'bearer (no session) exempt from CSRF');
} catch (Throwable) {
    T::ok(false, 'bearer (no session) exempt from CSRF');
}
$_SESSION = [];
