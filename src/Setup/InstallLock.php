<?php
declare(strict_types=1);

/**
 * Installation lock + progress marker in storage/ (never under public/).
 * The lock is a completion record, NOT an authorization token: after any
 * lock removal the installer re-verifies schema/seed/admin truth from the
 * database before allowing anything (see InstallerState).
 */
final class InstallLock
{
    public static function lockPath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/storage/install.lock';
    }

    public static function progressPath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/storage/setup-progress.json';
    }

    public static function exists(string $projectRoot): bool
    {
        return is_file(self::lockPath($projectRoot));
    }

    /** @param array<string,mixed> $info */
    public static function create(string $projectRoot, array $info): void
    {
        $payload = json_encode(
            array_merge(['locked_at' => gmdate('c')], $info),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if (@file_put_contents(self::lockPath($projectRoot), (string) $payload, LOCK_EX) === false) {
            throw new AppException('Could not write installation lock.', 500);
        }
        @chmod(self::lockPath($projectRoot), 0600);
    }

    /** @return array<string,mixed> */
    public static function readProgress(string $projectRoot): array
    {
        $p = self::progressPath($projectRoot);
        if (!is_file($p)) {
            return [];
        }
        $d = json_decode((string) @file_get_contents($p), true);
        return is_array($d) ? $d : [];
    }

    /** @param array<string,mixed> $progress */
    public static function writeProgress(string $projectRoot, array $progress): void
    {
        @file_put_contents(
            self::progressPath($projectRoot),
            (string) json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
