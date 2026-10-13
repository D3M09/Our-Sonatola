<?php
declare(strict_types=1);
// ScopePolicy: explicit union-scope without role-name hardcoding in middleware.
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/AppException.php';
require_once __DIR__ . '/../src/Core/ScopePolicy.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Middleware/RbacMiddleware.php';

echo "scope_policy:\n";
T::ok(ScopePolicy::isUnionScoped(['union_admin']) === true, 'union_admin union-scoped');
T::ok(ScopePolicy::isUnionScoped(['service_officer']) === true, 'service_officer union-scoped');
T::ok(ScopePolicy::isUnionScoped(['volunteer_coordinator']) === true, 'volunteer_coordinator future union-scoped');
T::ok(ScopePolicy::isUnionScoped(['upazila_admin']) === false, 'upazila_admin global');
T::ok(ScopePolicy::isUnionScoped(['editor']) === false, 'editor global');
T::ok(ScopePolicy::isUnionScoped(['citizen']) === false, 'citizen global');
T::ok(ScopePolicy::isUnionScoped([]) === false, 'empty roles global');

// Middleware must delegate (no hardcoded role list)
$mw = (string) file_get_contents(__DIR__ . '/../src/Middleware/RbacMiddleware.php');
T::ok(str_contains($mw, 'ScopePolicy::isUnionScoped'), 'middleware delegates to ScopePolicy');

// Volunteer coordinator is actually scoped end-to-end
$req = new Request('GET', '/x');
$req->user = ['id' => 3, 'union_id' => 9, 'roles' => ['volunteer_coordinator'], 'permissions' => []];
T::ok(RbacMiddleware::allowedUnionId($req) === 9, 'volunteer coordinator scoped to own union');
$req->user = ['id' => 4, 'union_id' => null, 'roles' => ['upazila_admin'], 'permissions' => []];
T::ok(RbacMiddleware::allowedUnionId($req) === null, 'upazila admin global');

// Migration + seed carry the scope column
T::ok(str_contains((string) file_get_contents(__DIR__ . '/../database/migrations/011_roles_scope.sql'), "scope ENUM('global','union')"), '011 adds scope enum');
$seed = (string) file_get_contents(__DIR__ . '/../database/seeds/001_roles_permissions.sql');
T::ok(str_contains($seed, "'union_admin'") && str_contains($seed, "'union'"), 'seed marks union roles');
