<?php
declare(strict_types=1);

/**
 * Safe first-admin bootstrap (CLI only, never web-accessible).
 * Usage:
 *   php scripts/create_admin.php --name="Admin" --email="admin@example.com" [--phone=...] [--force]
 *
 * The password is NEVER taken from the command line (it would leak into shell
 * history and process listings). It is read twice through a hidden interactive
 * prompt (terminal echo disabled via stty) and must be at least 12 characters.
 * The command fails closed when stdin is not an interactive terminal, when the
 * terminal state cannot be saved/restored, or when stty cannot disable echo.
 * Refuses to run if a super_admin already exists unless --force is given.
 * Requires a POSIX interactive terminal (on Windows use WSL or another POSIX
 * terminal such as Git Bash).
 */

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/src/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

/**
 * Read a secret from the terminal with echo disabled.
 * Fail-closed: requires an interactive TTY and a working stty facility.
 * The value is never printed, logged, or included in any message.
 */
function prompt_hidden(string $label): string
{
    // --- 1. Require a real interactive terminal (before any DB access). ---
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "This command requires an interactive terminal for secure password entry.\n");
        fwrite(STDERR, "Do not pass the password on the command line; run it from a real terminal (on Windows use WSL or another POSIX terminal).\n");
        exit(1);
    }
    if (!function_exists('shell_exec')) {
        fwrite(STDERR, "Cannot disable terminal echo (shell_exec is unavailable); refusing to read the password.\n");
        exit(1);
    }

    // --- 2. Save the exact terminal state. ---
    // The value comes from stty (not from the user). It is still validated against
    // a conservative allowlist and shell-escaped before any use.
    $saved = shell_exec('stty -g 2>/dev/null');
    $saved = is_string($saved) ? trim($saved) : '';
    if ($saved === '' || preg_match('/^[0-9A-Za-z:;._-]+$/', $saved) !== 1) {
        fwrite(STDERR, "Cannot save the terminal state (stty unavailable); refusing to read the password.\n");
        exit(1);
    }

    $restore = static function () use ($saved): void {
        // $saved was validated above and is escaped here — no raw shell interpolation.
        @shell_exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null');
    };
    // Additional protection: restore echo even if the process terminates unexpectedly.
    register_shutdown_function($restore);

    // --- 3. Disable echo and confirm the operation actually succeeded. ---
    fwrite(STDOUT, $label);
    $off = (string) shell_exec('stty -echo 2>&1; printf "RC:%s" "$?"');
    if (!str_ends_with(trim($off), 'RC:0')) {
        $restore();
        fwrite(STDERR, "Could not disable terminal echo; refusing to read the password.\n");
        exit(1);
    }

    // --- 4. Read the secret; always restore the terminal. ---
    try {
        $line = fgets(STDIN);
    } finally {
        $restore();
        fwrite(STDOUT, "\n");
    }

    if ($line === false) {
        fwrite(STDERR, "No password entered.\n");
        exit(1);
    }

    // --- 5. Verify echo restoration actually took effect before continuing. ---
    $now = shell_exec('stty -g 2>/dev/null');
    if (!is_string($now) || trim($now) !== $saved) {
        fwrite(STDERR, "Terminal state could not be verified after reading the password; aborting.\n");
        exit(1);
    }

    // Strip only the trailing line ending so intentional spaces are preserved.
    return rtrim($line, "\r\n");
}

// Reject a legacy password argument safely (defense-in-depth: never silently accept).
// The supplied value is never printed or logged.
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '--password' || str_starts_with((string) $arg, '--password=')) {
        fwrite(STDERR, "Do not pass the password on the command line. Run the command without --password; it will be read at a hidden prompt.\n");
        exit(1);
    }
}

$opts = getopt('', ['name:', 'email:', 'phone::', 'force']);
if ($opts === false) {
    $opts = [];
}
$name = trim((string) ($opts['name'] ?? ''));
$email = strtolower(trim((string) ($opts['email'] ?? '')));
$phone = isset($opts['phone']) ? trim((string) $opts['phone']) : null;

if ($name === '' || $email === '') {
    fwrite(STDERR, "Usage: php scripts/create_admin.php --name=\"...\" --email=\"...\" [--phone=...] [--force]\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email.\n");
    exit(1);
}

$pass = prompt_hidden('Admin password (min 12 chars): ');
$confirm = prompt_hidden('Confirm password: ');
if (!hash_equals($pass, $confirm)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
unset($confirm);
if (strlen($pass) < 12) {
    fwrite(STDERR, "Admin password must be at least 12 characters.\n");
    exit(1);
}

try {
    $pdo = DB::pdo();
} catch (Throwable $e) {
    // Do not print raw exception details (they can expose host/user configuration).
    fwrite(STDERR, "Database connection failed. Check the database configuration.\n");
    if (Config::isDebug()) {
        fwrite(STDERR, 'Debug: ' . $e->getMessage() . "\n");
    }
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
    $pass = null; // plaintext no longer needed once hashed
    $uid = (int) $pdo->lastInsertId();
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug='super_admin' LIMIT 1")->fetchColumn();
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:u,:r)')->execute([':u' => $uid, ':r' => $roleId]);
    $pdo->commit();
    echo "Super admin created: id={$uid} email={$email}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Do not print raw exception details (they can expose schema/config).
    fwrite(STDERR, "Failed to create the admin account; no changes were committed.\n");
    if (Config::isDebug()) {
        fwrite(STDERR, 'Debug: ' . $e->getMessage() . "\n");
    }
    exit(1);
}
