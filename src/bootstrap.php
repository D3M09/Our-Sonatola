<?php
declare(strict_types=1);

/** Simple PSR-4-ish autoloader for src/ (class name => file search). */
spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/../src/';
    $tried = [
        $base . 'Core/' . $class . '.php',
        $base . 'Middleware/' . $class . '.php',
        $base . 'Repositories/' . $class . '.php',
        $base . 'Services/' . $class . '.php',
        $base . 'Controllers/' . $class . '.php',
    ];
    foreach ($tried as $f) {
        if (is_file($f)) {
            require_once $f;
            return;
        }
    }
    // Config is not a namespaced class file layout
    if ($class === 'Config' && is_file(__DIR__ . '/../config/config.php')) {
        require_once __DIR__ . '/../config/config.php';
    }
});

require_once __DIR__ . '/../config/config.php';

$ROOT = dirname(__DIR__);
Config::load($ROOT);

// ---- Secure session for browser admin ----
$secure = Config::getBool('COOKIE_SECURE', true);
// On plain http local dev, Secure cookies won't be sent; auto-detect
if (($ROOT !== '' && Config::get('APP_ENV', 'production') !== 'production') && empty($_SERVER['HTTPS'])) {
    // keep configured value in prod, relax only via env in dev
}
session_name(Config::get('SESSION_NAME', 'sonatola_sid'));
$cookieParams = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure && (!empty($_SERVER['HTTPS']) || Config::get('APP_ENV') === 'production'),
    'httponly' => true,
    'samesite' => Config::get('CORS_ALLOWED_ORIGINS', '') !== '' ? 'None' : Config::get('COOKIE_SAMESITE', 'Lax'),
];
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params($cookieParams);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// ---- Error handling: no stack traces / secrets to clients in production ----
set_exception_handler(function (Throwable $e): void {
    $code = 500;
    $msg = 'Internal server error.';
    $errCode = 'internal_error';
    if ($e instanceof AppException) {
        $code = $e->statusCode();
        $msg = $e->getMessage();
        $errCode = $e->errorCode();
    }
    try {
        Logger::default()->error(get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    } catch (Throwable) {
    }
    if (!headers_sent()) {
        $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
        if ($isApi) {
            Response::error(Config::isDebug() ? $e->getMessage() : $msg, $code, $errCode);
        } else {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
            echo '<h1>' . $code . '</h1><p>' . Esc::h(Config::isDebug() ? $e->getMessage() : $msg) . '</p>';
        }
    }
});

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

// ---- Restricted CORS ----
(function (): void {
    $allowed = array_filter(array_map('trim', explode(',', Config::get('CORS_ALLOWED_ORIGINS', ''))));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
})();
