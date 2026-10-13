<?php
declare(strict_types=1);

/**
 * Configuration loader.
 * Reads .env (project root, one level above public/) without external deps.
 * All secrets stay outside the public document root.
 */

final class Config
{
    /** @var array<string,string> */
    private static array $cache = [];

    public static function load(string $rootDir): void
    {
        $envFile = rtrim($rootDir, '/') . '/.env';
        if (!is_file($envFile)) {
            return;
        }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            // Strip surrounding quotes
            if (strlen($val) >= 2) {
                $f = $val[0];
                $l = $val[strlen($val) - 1];
                if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
                    $val = substr($val, 1, -1);
                }
            }
            self::$cache[$key] = $val;
            // Also expose via environment for child processes
            if (getenv($key) === false) {
                putenv($key . '=' . $val);
            }
            $_ENV[$key] = $val;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $v = getenv($key);
        if ($v !== false) {
            return (string) $v;
        }
        return $_ENV[$key] ?? $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = strtolower(trim(self::get($key, $default ? 'true' : 'false')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::get($key, (string) $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return array<string,mixed> */
    public static function db(): array
    {
        return [
            'host' => self::get('DB_HOST', 'localhost'),
            'port' => self::getInt('DB_PORT', 3306),
            'name' => self::get('DB_NAME', 'sonatola'),
            'user' => self::get('DB_USER', 'root'),
            'pass' => self::get('DB_PASS', ''),
            'charset' => self::get('DB_CHARSET', 'utf8mb4'),
        ];
    }

    public static function isDebug(): bool
    {
        return self::getBool('APP_DEBUG', false);
    }
}
