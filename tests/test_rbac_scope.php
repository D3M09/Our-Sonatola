<?php
declare(strict_types=1);
// RBAC + union scoping logic (no DB — uses Request::$user arrays directly)
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/AppException.php';
require_once __DIR__ . '/../src/Core/ScopePolicy.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Middleware/RbacMiddleware.php';

echo "rbac:\n";

function mkReq(?array $user): Request
{
    $r = new Request('GET', '/api/v1/complaints');
    $r->user = $user;
    return $r;
}

// Unauthenticated blocked
try {
    RbacMiddleware::requirePermission(mkReq(null), 'complaint.view');
    T::ok(false, 'unauth blocked');
} catch (AppException $e) {
    T::ok($e->statusCode() === 401, 'unauth blocked (401)');
}

// Citizen without permission blocked from admin endpoint
$citizen = ['id' => 5, 'union_id' => 2, 'roles' => ['citizen'], 'permissions' => []];
try {
    RbacMiddleware::requirePermission(mkReq($citizen), 'complaint.view');
    T::ok(false, 'citizen privilege escalation blocked');
} catch (AppException $e) {
    T::ok($e->statusCode() === 403, 'citizen privilege escalation blocked (403)');
}

// Union admin scoped to own union
$unionAdmin = ['id' => 9, 'union_id' => 3, 'roles' => ['union_admin'], 'permissions' => ['complaint.view']];
T::ok(RbacMiddleware::allowedUnionId(mkReq($unionAdmin)) === 3, 'union admin scoped to own union');

// Cross-union read blocked (changing ID in URL)
try {
    RbacMiddleware::assertUnionAccess(mkReq($unionAdmin), ['union_id' => 4]);
    T::ok(false, 'cross-union access blocked');
} catch (AppException $e) {
    T::ok($e->statusCode() === 403, 'cross-union access blocked (403)');
}

// Same-union allowed
try {
    RbacMiddleware::assertUnionAccess(mkReq($unionAdmin), ['union_id' => 3]);
    T::ok(true, 'same-union allowed');
} catch (Throwable) {
    T::ok(false, 'same-union allowed');
}

// Create payload forced to own union even if attacker sends another union_id
$forced = RbacMiddleware::scopeCreateData(mkReq($unionAdmin), ['union_id' => 99, 'subject' => 'x']);
T::ok(($forced['union_id'] ?? 0) === 3, 'create union_id forced to scope');

// Super admin unrestricted
$super = ['id' => 1, 'union_id' => null, 'roles' => ['super_admin'], 'permissions' => []];
T::ok(RbacMiddleware::allowedUnionId(mkReq($super)) === null, 'super admin unrestricted');
try {
    RbacMiddleware::requirePermission(mkReq($super), 'anything.missing');
    T::ok(true, 'super admin bypasses permission checks');
} catch (Throwable) {
    T::ok(false, 'super admin bypasses permission checks');
}

// SQLi in ID param must not bypass scope: non-numeric union still fails strict int compare
try {
    RbacMiddleware::assertUnionAccess(mkReq($unionAdmin), ['union_id' => "3 OR 1=1"]);
    T::ok(false, 'sqli union bypass blocked');
} catch (AppException $e) {
    T::ok(true, 'sqli union bypass blocked');
}
