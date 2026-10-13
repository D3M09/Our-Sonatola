<?php
declare(strict_types=1);
// SQLi/XSS static + unit regression tests — no database contact.
require_once __DIR__ . '/../src/Core/AppException.php';
require_once __DIR__ . '/../src/Core/Validator.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Esc.php';
require_once __DIR__ . '/../src/Repositories/BaseRepository.php';

echo "sqli_static:\n";
// No raw superglobal interpolation in repository SQL.
$repoSrc = '';
foreach (glob(__DIR__ . '/../src/Repositories/*.php') ?: [] as $f) {
    $repoSrc .= "\n" . file_get_contents($f);
}
T::ok(!preg_match('/\$_(GET|POST|REQUEST|COOKIE)/', $repoSrc), 'repositories never touch superglobals');
// Every ORDER BY is a fixed string (no user-controlled sort columns/directions).
foreach (['ORDER BY u.id DESC', 'ORDER BY sort_order, name_en', 'ORDER BY id DESC', 'ORDER BY n.published_at DESC', 'ORDER BY published_at DESC', 'ORDER BY id', 'ORDER BY name_en'] as $fixed) {
    T::ok(str_contains($repoSrc, $fixed), "fixed ORDER BY present: {$fixed}");
}
T::ok(!preg_match('/ORDER BY\s*["\']?\s*\$/', $repoSrc), 'no interpolated ORDER BY');
// LIMIT/OFFSET are int-cast in the shared paginator.
$base = (string) file_get_contents(__DIR__ . '/../src/Repositories/BaseRepository.php');
T::ok(str_contains($base, '((int) $perPage)') && str_contains($base, '((int) $offset)'), 'LIMIT/OFFSET int-cast');

echo "sqli_like_escape:\n";
// likePattern neutralizes LIKE metacharacters.
$ref = new ReflectionMethod(BaseRepository::class, 'likePattern');
T::ok($ref->invoke(null, '%') === '%!%%', 'percent escaped');
T::ok($ref->invoke(null, '_') === '%!_%', 'underscore escaped');
T::ok($ref->invoke(null, '!') === '%!!%', 'escape char itself escaped');
T::ok($ref->invoke(null, '\\') === '%\\%', 'backslash needs no escape with !-escapes');
T::ok($ref->invoke(null, 'plain') === '%plain%', 'plain term wrapped only');
// Every LIKE site pairs the escaped pattern with an ESCAPE clause.
T::ok(substr_count($repoSrc, 'ESCAPE') >= 8, 'ESCAPE clause on all LIKE sites');
// Placeholders must be unique per LIKE (native prepares reject repeats);
// no reused bare :q may remain.
T::ok(!preg_match('/LIKE :q[\s]/', $repoSrc), 'no reused :q placeholder');
T::ok(preg_match_all('/LIKE :q[123]/', $repoSrc) >= 9, 'distinct :q1/:q2/:q3 placeholders');

echo "sqli_input_validation:\n";
$evil = ["' OR '1'='1", "' UNION SELECT * FROM users --", "1; DROP TABLE users; --", "3 OR 1=1"];
foreach ($evil as $p) {
    $e = Validator::validate(['phone' => $p], ['phone' => 'phone']);
    T::ok(isset($e['phone']), "malicious phone rejected: {$p}");
}
$e = Validator::validate(['email' => "' OR 1=1 --"], ['email' => 'required|email']);
T::ok(isset($e['email']), 'sqli email rejected by format rule');

echo "xss_output:\n";
T::ok(Esc::h('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'script tag neutralized');
T::ok(Esc::h('"onmouseover="x') === '&quot;onmouseover=&quot;x', 'attribute quotes neutralized');
T::ok(Esc::h("a'b") === 'a&#039;b', 'single quotes neutralized');
// Installer notices sink is escaped (was a latent unescaped echo).
$setup = (string) file_get_contents(__DIR__ . '/../public/setup.php');
T::ok(str_contains($setup, 'echo Esc::h((string) $n)'), 'installer notices escaped at sink');
T::ok(!preg_match('/echo \$n;/', $setup), 'no raw notice echo remains');
// Bootstrap error page escapes debug/internal messages.
$boot = (string) file_get_contents(__DIR__ . '/../src/bootstrap.php');
T::ok(str_contains($boot, 'Esc::h('), 'error handler escapes output');
// JSON API: correct content type, no JSONP callback parameter.
$resp = (string) file_get_contents(__DIR__ . '/../src/Core/Response.php');
T::ok(str_contains($resp, 'application/json'), 'JSON content type set');
T::ok(!preg_match('/callback|jsonp/i', $resp), 'no JSONP callback support');

echo "xss_log_forging:\n";
$_SERVER['HTTP_USER_AGENT'] = "Mozilla\r\nINJECTED: yes\x00\x1f";
$req = new Request('GET', '/');
$ua = $req->userAgent();
T::ok(!preg_match('/[\r\n\x00-\x1F\x7F]/', $ua), 'control chars stripped from UA');
T::ok(!preg_match('/[\r\n]/', $ua) && str_contains($ua, 'Mozilla'), 'UA stays single-line, content preserved');
unset($_SERVER['HTTP_USER_AGENT']);
