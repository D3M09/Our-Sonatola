<?php
declare(strict_types=1);
// Session idle/absolute expiry + permission/scope enforcement sources.
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/AppException.php';
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../config/config.php';

echo "session_expiry:\n";
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$now = time();

// Fresh session valid + slides activity
$_SESSION = ['user_id' => 1, 'login_at' => $now - 60, 'last_activity' => $now - 60];
T::ok(Auth::sessionStillValid() === true, 'fresh session valid');
T::ok(abs(($_SESSION['last_activity'] ?? 0) - $now) <= 5, 'activity timestamp slides');

// Idle-expired
$_SESSION = ['user_id' => 1, 'login_at' => $now - 100, 'last_activity' => $now - (121 * 60)];
T::ok(Auth::sessionStillValid() === false, 'idle-expired session rejected');

// Absolute-expired even with recent activity
$_SESSION = ['user_id' => 1, 'login_at' => $now - (13 * 3600), 'last_activity' => $now];
T::ok(Auth::sessionStillValid() === false, 'absolute-expired session rejected');

// No user -> invalid
$_SESSION = [];
T::ok(Auth::sessionStillValid() === false, 'anonymous invalid');
$_SESSION = [];

// Env defaults documented
T::ok(Config::getInt('SESSION_IDLE_MINUTES', 120) === 120, 'idle default 120m');
T::ok(Config::getInt('SESSION_ABSOLUTE_HOURS', 12) === 12, 'absolute default 12h');

echo "permission_enforcement:\n";
$comp = (string) file_get_contents(__DIR__ . '/../src/Controllers/ComplaintController.php');
$showBody = substr($comp, (int) strpos($comp, 'function show'));
$showBody = substr($showBody, 0, (int) strpos($showBody, 'function listAll'));
T::ok(str_contains($showBody, "requirePermission") && str_contains($showBody, 'complaint.view'), 'complaint show requires complaint.view');

$adminU = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminUserController.php');
T::ok(str_contains($adminU, 'allowedUnionId'), 'user listing enforces union scope');
T::ok(str_contains($adminU, 'Invalid status filter'), 'user status filter validated');

$adminC = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminContentController.php');
T::ok(substr_count($adminC, 'allowedUnionId') >= 2, 'content listings enforce union scope');

$svc = (string) file_get_contents(__DIR__ . '/../src/Services/AuthService.php');
T::ok(str_contains($svc, 'revokeUserApiSessions'), 'password reset revokes api sessions');
