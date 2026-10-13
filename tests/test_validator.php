<?php
declare(strict_types=1);
// Validator + input tests (no DB)
require_once __DIR__ . '/../src/Core/Validator.php';
require_once __DIR__ . '/../src/Core/AppException.php';

echo "validator:\n";
$e = Validator::validate(['name' => '', 'email' => 'bad'], ['name' => 'required|max:100', 'email' => 'required|email']);
T::ok(isset($e['name']) && isset($e['email']), 'required + email invalid caught');

$e = Validator::validate(['email' => 'a@b.com', 'password' => 'short'], ['email' => 'required|email', 'password' => 'required|min:8']);
T::ok(isset($e['password']), 'min length enforced');

$e = Validator::validate(['status' => 'hacked'], ['status' => 'in:draft,published']);
T::ok(isset($e['status']), 'allowlist (in:) enforced');

$e = Validator::validate(['phone' => "1'; DROP TABLE users; --"], ['phone' => 'phone']);
T::ok(isset($e['phone']), 'malicious phone rejected');

[$page, $per, $off] = Validator::paginate(['page' => '999', 'per_page' => '500']);
T::ok($per === 100 && $page === 999 && $off === ($page - 1) * $per, 'pagination caps per_page at 100');

[$page, $per, ] = Validator::paginate(['page' => '-5', 'per_page' => '0']);
T::ok($page === 1 && $per === 1, 'pagination floors at 1');
