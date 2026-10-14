<?php
declare(strict_types=1);
// Static + behavioral checks for scripts/create_admin.php password handling.
// No account is created and no database row is written: every invocation below
// uses piped stdin (/dev/null) and no --force, so it fails closed before DB access.
require_once __DIR__ . '/T.php';

echo "create_admin_password_input:\n";

$root = dirname(__DIR__);
$scriptPath = $root . '/scripts/create_admin.php';
$src = (string) file_get_contents($scriptPath);

// --- 1. getopt() no longer accepts a password option (others preserved). ---
if (preg_match('/getopt\((.*?)\);/s', $src, $m)) {
    T::ok(!str_contains($m[1], "'password"), 'getopt has no password option');
    foreach (["'name", "'email", "'phone", "'force"] as $opt) {
        T::ok(str_contains($m[1], $opt), "getopt keeps {$opt} option");
    }
} else {
    T::ok(false, 'getopt call found');
}

// --- 2. Usage text does not advertise a password argument. ---
if (preg_match('/"Usage: php scripts\/create_admin\.php[^"]*"/', $src, $u)) {
    T::ok(!str_contains($u[0], '--password'), 'usage text does not advertise --password');
} else {
    T::ok(false, 'usage text found');
}

// --- 3. Legacy --password rejected (value never printed/logged). ---
T::ok(str_contains($src, "=== '--password'") && str_contains($src, 'str_starts_with'),
    'legacy --password rejected by argv guard');

// --- 4. Non-TTY fails closed BEFORE any database access. ---
T::ok(str_contains($src, 'stream_isatty(STDIN)'), 'stdin TTY check present (stream_isatty)');
T::ok(!str_contains($src, 'posix_isatty'), 'does not depend on posix_isatty');
$ttyPos = strpos($src, 'stream_isatty(STDIN)');
$dbPos = strpos($src, 'DB::pdo()');
T::ok($ttyPos !== false && $dbPos !== false && $ttyPos < $dbPos, 'TTY check precedes DB::pdo()');

// --- 5. Hidden input + echo disable/restore are present and checked. ---
T::ok(str_contains($src, 'stty -g'), 'terminal state saved with stty -g');
T::ok(str_contains($src, 'stty -echo'), 'echo disabled with stty -echo');
T::ok(str_contains($src, "shell_exec('stty '"), 'terminal state restored');
T::ok(str_contains($src, 'register_shutdown_function'), 'shutdown restoration registered');
T::ok(!preg_match('/\breadline\s*\(/', $src), 'does not rely on readline()');
T::ok(str_contains($src, 'escapeshellarg'), 'shell arguments escaped');
T::ok(str_contains($src, 'RC:0'), 'stty -echo success is verified');
T::ok(str_contains($src, 'could not be verified'), 'echo restoration is verified');

// --- 6. Confirmation, minimum length, and hashing are preserved. ---
T::ok(str_contains($src, 'hash_equals('), 'confirmation compared with hash_equals');
T::ok(preg_match('/strlen\(\$pass\)\s*<\s*12/', $src) === 1, 'min length 12 retained');
T::ok(str_contains($src, 'PASSWORD_DEFAULT'), 'password_hash(PASSWORD_DEFAULT) retained');

// --- 7. The password value is never printed or logged. ---
$printsSecret = false;
foreach (preg_split('/\R/', $src) ?: [] as $line) {
    if ((str_contains($line, 'echo ') || str_contains($line, 'fwrite(') || str_contains($line, 'error_log'))
        && (str_contains($line, '$pass') || str_contains($line, '$confirm') || str_contains($line, '$line'))) {
        $printsSecret = true;
    }
}
T::ok(!$printsSecret, 'password never printed or logged');

// --- 8. Behavioral: failures are fail-closed; arguments cannot create an account. ---
if (!function_exists('exec') || PHP_OS_FAMILY === 'Windows') {
    T::skip('non-TTY fail-closed run', 'exec/POSIX redirection unavailable');
    T::skip('legacy --password rejected run', 'exec/POSIX redirection unavailable');
    return;
}
$php = PHP_BINARY;
$run = static function (array $args) use ($php, $scriptPath): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($scriptPath);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    $code = -1;
    exec($cmd . ' < /dev/null 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

[$code, $text] = $run(['--name=Security Test', '--email=security-test@example.com']);
T::ok($code !== 0, 'non-TTY run exits non-zero');
T::ok(str_contains($text, 'interactive terminal'), 'non-TTY run reports the safe error');
T::ok(!str_contains($text, 'Super admin created'), 'non-TTY run creates no account');

$secret = 'LegacyValue-DoNotLeak-123';
[$code, $text] = $run(['--name=Security Test', '--email=security-test@example.com', '--password=' . $secret]);
T::ok($code !== 0, 'legacy --password run exits non-zero');
T::ok(!str_contains($text, $secret), 'legacy password value is never printed');
T::ok(!str_contains($text, 'Super admin created'), 'legacy --password run creates no account');
