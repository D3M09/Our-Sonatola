<?php
declare(strict_types=1);
// Static + contract checks for the admin Users + Audit Log UI (4C-2).
// No database, no account, no writes. Pure source inspection.
require_once __DIR__ . '/T.php';

echo "admin_ui_users_audit:\n";

$root = dirname(__DIR__);
$read = static function (string $rel) use ($root): string {
    $p = $root . '/' . $rel;
    return is_file($p) ? (string) file_get_contents($p) : '';
};

$users = $read('public/js/admin/users.js');
$audit = $read('public/js/admin/audit.js');
$shell = $read('public/js/admin/shell.js');
$app = $read('public/js/app.js');
$en = $read('public/js/locales/en.json');
$bn = $read('public/js/locales/bn.json');
$routes = $read('src/routes.php');

echo "files:\n";
T::ok($users !== '', 'users.js exists');
T::ok($audit !== '', 'audit.js exists');

echo "endpoints_and_csrf:\n";
T::ok(str_contains($users, "'/admin/users?page="), 'users.js GET /admin/users (paginated)');
T::ok(str_contains($users, "'/status'") && str_contains($users, 'api.put('), 'users.js status change via api.put (CSRF auto)');
T::ok(str_contains($users, "'/roles'") && str_contains($users, 'api.post('), 'users.js role assign via api.post (CSRF auto)');
T::ok(str_contains($audit, "'/admin/audit-logs?page="), 'audit.js GET /admin/audit-logs (paginated)');
T::ok(!str_contains($audit, 'api.put(') && !str_contains($audit, 'api.post(') && !str_contains($audit, 'api.del('),
    'audit.js is read-only (no writes)');

echo "permission_gating:\n";
T::ok(str_contains($users, "'user.manage'"), 'users.js gates status control on user.manage');
T::ok(str_contains($users, "'role.assign'"), 'users.js gates role assignment on role.assign');
T::ok(str_contains($users, "'role.view'"), 'users.js loads role list only with role.view');
T::ok(str_contains($users, "'super_admin'"), 'users.js restricts super_admin grant (mirrors backend)');
T::ok(str_contains($audit, 'audit.view') || str_contains($audit, 'no_permission'), 'audit.js surfaces permission denial honestly');

echo "xss_safety:\n";
T::ok(!str_contains($users, '.innerHTML') && !str_contains($audit, '.innerHTML'), 'no .innerHTML usage in new screens');
T::ok(!str_contains($users, 'insertAdjacentHTML') && !str_contains($audit, 'insertAdjacentHTML'), 'no insertAdjacentHTML usage in new screens');
T::ok(str_contains($users, 'setText(') && str_contains($audit, 'setText('), 'output via setText (textContent)');

echo "navigation:\n";
T::ok(str_contains($shell, "from './users.js"), 'shell.js imports users screen');
T::ok(str_contains($shell, "from './audit.js"), 'shell.js imports audit screen');
T::ok(preg_match('/key: \'users\'[^\n]*enabled: true/', $shell) === 1, 'users nav enabled');
T::ok(preg_match('/key: \'audit\'[^\n]*enabled: true/', $shell) === 1, 'audit nav enabled');
T::ok(str_contains($shell, "'#/admin/users'") && str_contains($shell, "'#/admin/audit'"), 'shell.js nav links set');
T::ok(str_contains($app, "'#/admin/users'"), 'app.js route #/admin/users');
T::ok(str_contains($app, "'#/admin/audit'"), 'app.js route #/admin/audit');

echo "api_contract_unchanged:\n";
T::ok(str_contains($routes, "'/api/v1/admin/users'"), 'routes keep GET /admin/users');
T::ok(str_contains($routes, "'/api/v1/admin/users/{id}/status'"), 'routes keep PUT /users/{id}/status');
T::ok(str_contains($routes, "'/api/v1/admin/users/{id}/roles'"), 'routes keep POST /users/{id}/roles');
T::ok(str_contains($routes, "'/api/v1/admin/roles'"), 'routes keep GET /admin/roles');
T::ok(str_contains($routes, "'/api/v1/admin/audit-logs'"), 'routes keep GET /admin/audit-logs');

echo "locales:\n";
$enData = json_decode($en, true);
$bnData = json_decode($bn, true);
T::ok(is_array($enData) && is_array($bnData), 'en/bn locale JSON parses');
$newKeys = [
    'admin.users_list_title', 'admin.users_col_status', 'admin.users_role_assign',
    'admin.users_status_active', 'admin.audit_list_title', 'admin.audit_col_metadata', 'admin.audit_note',
];
$present = true;
foreach ($newKeys as $k) {
    if (!is_array($enData) || !array_key_exists($k, $enData)) {
        $present = false;
    }
    if (!is_array($bnData) || !array_key_exists($k, $bnData)) {
        $present = false;
    }
}
T::ok($present, 'new admin.users_*/audit_* keys present in both locales');

$unreviewedOk = true;
foreach ([$enData, $bnData] as $data) {
    if (!is_array($data)) {
        $unreviewedOk = false;
        break;
    }
    foreach ($data as $k => $v) {
        if ((str_starts_with((string) $k, 'admin.users_') || str_starts_with((string) $k, 'admin.audit_'))
            && !str_ends_with((string) $v, '_TODO')) {
            $unreviewedOk = false;
        }
    }
}
T::ok($unreviewedOk, 'new strings still carry _TODO (human review not claimed)');

T::ok(is_array($enData) && array_key_exists('admin.queue_title', $enData) && array_key_exists('admin.roles_mapping_note', $enData),
    'existing en keys retained');
T::ok(is_array($bnData) && array_key_exists('admin.queue_title', $bnData) && array_key_exists('admin.roles_mapping_note', $bnData),
    'existing bn keys retained');
T::ok(is_array($enData) && is_array($bnData)
    && array_diff(array_keys($enData), array_keys($bnData)) === []
    && array_diff(array_keys($bnData), array_keys($enData)) === [],
    'en/bn key sets identical');
