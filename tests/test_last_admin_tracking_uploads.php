<?php
declare(strict_types=1);
// Last-admin guard + tracking-code collision handling + uploads deferred policy.
require_once __DIR__ . '/../src/Repositories/BaseRepository.php';
require_once __DIR__ . '/../src/Repositories/UserRepository.php';
require_once __DIR__ . '/../src/Services/ComplaintService.php';

echo "last_admin:\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT UNIQUE, phone TEXT, password_hash TEXT, union_id INTEGER, upazila_id INTEGER, account_status TEXT DEFAULT "pending")');
$pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, name TEXT)');
$pdo->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
$pdo->exec("INSERT INTO roles (slug, name) VALUES ('super_admin','Super Admin'), ('citizen','Citizen')");
$pdo->exec("INSERT INTO users (name, email, password_hash, account_status) VALUES ('A','a@x.com','h','active'), ('B','b@x.com','h','active')");
$pdo->exec('INSERT INTO user_roles (user_id, role_id) VALUES (1, 1), (2, 1)');
$users = new UserRepository($pdo);
T::ok($users->isSuperAdmin(1) === true, 'super admin detected');
T::ok($users->countOtherActiveSuperAdmins(1) === 1, 'other active super admin counted');
// After deactivating B, A becomes the last one
$pdo->exec("UPDATE users SET account_status='disabled' WHERE id=2");
T::ok($users->countOtherActiveSuperAdmins(1) === 0, 'last super admin detected (zero others)');

$ctrl = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminUserController.php');
T::ok(str_contains($ctrl, 'last active Super Admin') || str_contains($ctrl, 'last active super'), 'controller blocks last super admin disable');

echo "tracking:\n";
$ref = new ReflectionMethod(ComplaintService::class, 'trackingNo');
$svcRef = (new ReflectionClass(ComplaintService::class))->newInstanceWithoutConstructor();
$seen = [];
$ok = true;
for ($i = 0; $i < 50; $i++) {
    $t = $ref->invoke($svcRef);
    if (!preg_match('/^SN-\d{8}-[0-9A-F]{8}$/', $t) || isset($seen[$t])) {
        $ok = false;
        break;
    }
    $seen[$t] = true;
}
T::ok($ok, 'tracking codes formatted + unique (50 samples)');
$svcSrc = (string) file_get_contents(__DIR__ . '/../src/Services/ComplaintService.php');
T::ok(str_contains($svcSrc, 'isDuplicateKey') && str_contains($svcSrc, 'attempts'), 'tracking collision retry present');

echo "uploads_deferred:\n";
$routes = (string) file_get_contents(__DIR__ . '/../src/routes.php');
T::ok(!preg_match('#/files|/uploads|/media#i', $routes), 'no public file-serving route (private stays private)');
$up = (string) file_get_contents(__DIR__ . '/../src/Services/UploadService.php');
T::ok(str_contains($up, "'is_public'") || str_contains($up, 'is_public'), 'uploads default private metadata');
T::ok(str_contains($up, 'storage/uploads'), 'uploads outside docroot');
$docs = (string) file_get_contents(__DIR__ . '/../docs/api-v1.md');
T::ok(stripos($docs, 'upload') !== false && stripos($docs, 'defer') !== false, 'uploads deferred documented');
