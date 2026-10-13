<?php
declare(strict_types=1);
// Router + response format tests (no DB)
require_once __DIR__ . '/../src/Core/Router.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/AppException.php';

echo "router:\n";
$r = new Router();
$r->get('/api/v1/news/{slug}', 'handler');
$m = $r->match('GET', '/api/v1/news/hello-world');
T::ok($m !== null && ($m['params']['slug'] ?? '') === 'hello-world', 'param extraction');

T::ok($r->match('POST', '/api/v1/news/hello-world') === null, 'method mismatch 404');

T::ok($r->match('GET', '/api/v1/news/hello/world') === null, 'no greedy match across slashes');

echo "response:\n";
ob_start();
Response::json(['a' => 1], 200);
$out = (string) ob_get_clean();
$d = json_decode($out, true);
T::ok(($d['success'] ?? false) === true && ($d['data']['a'] ?? 0) === 1, 'json success envelope');

ob_start();
Response::error('Nope', 403, 'forbidden');
$out = (string) ob_get_clean();
$d = json_decode($out, true);
T::ok(($d['success'] ?? true) === false && ($d['error']['code'] ?? '') === 'forbidden', 'json error envelope');

ob_start();
Response::paginated([1, 2], 2, 10, 25);
$out = (string) ob_get_clean();
$d = json_decode($out, true);
T::ok(($d['meta']['pagination']['total_pages'] ?? 0) === 3, 'pagination meta');
