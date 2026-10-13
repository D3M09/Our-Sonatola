<?php
declare(strict_types=1);
// Password reset safely disabled by default; no raw token exposure.
require_once __DIR__ . '/../src/Core/Auth.php';
require_once __DIR__ . '/../src/Core/Validator.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/AppException.php';
require_once __DIR__ . '/../src/Core/Logger.php';
require_once __DIR__ . '/../src/Core/Audit.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Repositories/BaseRepository.php';
require_once __DIR__ . '/../src/Repositories/UserRepository.php';
require_once __DIR__ . '/../src/Services/AuthService.php';

echo "reset_disabled:\n";
// Env default must be disabled
T::ok(Config::getBool('PASSWORD_RESET_ENABLED', false) === false, 'reset env defaults to disabled');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT UNIQUE, phone TEXT, password_hash TEXT, union_id INTEGER, upazila_id INTEGER, account_status TEXT DEFAULT "pending", email_verified_at TEXT, last_login_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, name TEXT)');
$pdo->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))');
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$pdo->exec('CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, type TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER, action TEXT, entity_type TEXT, entity_id TEXT, ip_address TEXT, user_agent TEXT, metadata_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO users (name, email, password_hash, account_status) VALUES ('A', 'a@example.com', 'x', 'active')");

$svc = new AuthService($pdo);
$req = new Request('POST', '/api/v1/password/request');
T::ok($svc->isPasswordResetEnabled() === false, 'reset disabled without env+setting');

// Request must not issue tokens when disabled + must not throw (anti-enumeration)
$svc->requestPasswordReset('a@example.com', $req);
$n = (int) $pdo->query("SELECT COUNT(*) FROM auth_tokens WHERE type='password_reset'")->fetchColumn();
T::ok($n === 0, 'no token issued while disabled');

// Unknown email also silent
$svc->requestPasswordReset('nobody@example.com', $req);
T::ok(true, 'unknown email silent (no exception)');

// Source must never log/echo raw tokens
$src = (string) file_get_contents(__DIR__ . '/../src/Services/AuthService.php');
T::ok(!preg_match('/Logger.*\$raw|error_log.*\$raw|echo.*\$raw/i', $src), 'raw token never logged/echoed');
$ctrl = (string) file_get_contents(__DIR__ . '/../src/Controllers/AuthController.php');
T::ok(!str_contains($ctrl, 'token') || !preg_match('/Response::json.*\$raw|\$token.*raw/i', $ctrl), 'controller never exposes raw reset token');
