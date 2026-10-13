<?php
declare(strict_types=1);

/**
 * Safe first-admin bootstrap (CLI only, never web-accessible).
 * Usage:
 *   php scripts/create_admin.php --name="Admin" --email="admin@example.com" --password="..." [--phone=...]
 * Refuses to run if a super_admin already exists unless --force is given with explicit confirmation.
 */

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/src/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = getopt('', ['name:', 'email:', 'password:', 'phone::', 'force']);
$name = trim((string) ($opts['name'] ?? ''));
$email = strtolower(trim((string) ($opts['email'] ?? '')));
$pass = (string) ($opts['password'] ?? '');
$phone = isset($opts['phone']) ? trim((string) $opts['phone']) : null;

if ($name === '' || $email === '' || $pass === '') {
    fwrite(STDERR, "Usage: php scripts/create_admin.php --name=\"...\" --email=\"...\" --password=\"...\" [--phone=...] [--force]\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email.\n");
    exit(1);
}
if (strlen($pass) < 12) {
    fwrite(STDERR, "Admin password must be at least 12 characters.\n");
    exit(1);
}

try {
    $pdo = DB::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Guard: refuse if super_admin exists
$st = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='super_admin'");
$st->execute();
$count = (int) $st->fetchColumn();
if ($count > 0 && !isset($opts['force'])) {
    fwrite(STDERR, "A super_admin already exists ({$count}). Re-run with --force to create another.\n");
    exit(1);
}

$st = $pdo->prepare('SELECT id FROM users WHERE email=:e LIMIT 1');
$st->execute([':e' => $email]);
if ($st->fetch()) {
    fwrite(STDERR, "Email already exists.\n");
    exit(1);
}

// Upazila id for Sonatola if present
$upazilaId = null;
try {
    $r = $pdo->query("SELECT id FROM upazilas WHERE name_en='Sonatola' LIMIT 1")->fetch();
    $upazilaId = $r ? (int) $r['id'] : null;
} catch (Throwable) {
}

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare('INSERT INTO users (name,email,phone,password_hash,upazila_id,account_status) VALUES (:n,:e,:p,:h,:u,\'active\')');
    $ins->execute([':n' => $name, ':e' => $email, ':p' => $phone ?: null, ':h' => password_hash($pass, PASSWORD_DEFAULT), ':u' => $upazilaId]);
    $uid = (int) $pdo->lastInsertId();
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug='super_admin' LIMIT 1")->fetchColumn();
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:u,:r)')->execute([':u' => $uid, ':r' => $roleId]);
    $pdo->commit();
    echo "Super admin created: id={$uid} email={$email}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Failed: " . $e->getMessage() . "\n");
    exit(1);
}
