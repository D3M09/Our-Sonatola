<?php
declare(strict_types=1);

/**
 * Front controller — public document root must point here.
 * All /api/v1 traffic + minimal public pages flow through this file.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/routes.php';

try {
    $pdo = DB::pdo();
} catch (Throwable $e) {
    // DB down: still serve health with 503, never leak credentials
    Logger::default()->error('DB connect failed: ' . $e->getMessage());
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_starts_with(parse_url($uri, PHP_URL_PATH) ?: '', '/api/')) {
        Response::error('Service temporarily unavailable.', 503, 'db_unavailable');
    } else {
        Response::html('<h1>503</h1><p>Service temporarily unavailable.</p>', 503);
    }
    exit;
}

$req = Request::fromGlobals();
AuthMiddleware::attach($pdo, $req);

$router = new Router();
registerRoutes($router, $pdo);

$path = $req->path;
// Support shared hosts without rewrite: ?route=/api/v1/news fallback
if (isset($_GET['route']) && is_string($_GET['route']) && str_starts_with($_GET['route'], '/')) {
    $path = $_GET['route'];
}

$match = $router->match($req->method, $path);
if ($match === null) {
    // Method mismatch hint: try ANY path existence? keep simple 404
    if (str_starts_with($path, '/api/')) {
        Response::error('Not found.', 404, 'not_found');
    } else {
        Response::html('<h1>404</h1><p>Not found.</p>', 404);
    }
    exit;
}

[$handler, $params] = [$match['handler'], $match['params']];
if (is_callable($handler)) {
    $handler($req, $params);
} elseif (is_array($handler) && count($handler) === 2) {
    [$obj, $method] = $handler;
    $obj->$method($req, $params);
} else {
    Response::error('Route misconfigured.', 500);
}
