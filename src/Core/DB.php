<?php
declare(strict_types=1);

/** PDO connection manager (singleton). All queries use prepared statements. */
final class DB
{
    private static ?PDO $pdo = null;

    /** @param array<string,mixed>|null $cfg */
    public static function pdo(?array $cfg = null): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if ($cfg === null) {
            $cfg = Config::db();
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['name'],
            $cfg['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Enforce strict SQL mode where available
        try {
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable) {
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function setTestInstance(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
