<?php
declare(strict_types=1);

/**
 * Pre-installation environment checks. Pure logic, no database contact.
 * Each check returns ['label' => string, 'ok' => bool, 'detail' => string].
 */
final class SetupRequirements
{
    /** @return array<int,array{label:string,ok:bool,detail:string}> */
    public static function check(string $projectRoot): array
    {
        $checks = [];
        $checks[] = [
            'label' => 'PHP version >= 8.1',
            'ok' => PHP_VERSION_ID >= 80100,
            'detail' => 'running ' . PHP_VERSION,
        ];
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo'] as $ext) {
            $ok = extension_loaded($ext);
            $checks[] = [
                'label' => "PHP extension {$ext}",
                'ok' => $ok,
                'detail' => $ok ? 'loaded' : 'missing — enable it in hosting PHP settings',
            ];
        }
        $rootWritable = is_writable($projectRoot);
        $checks[] = [
            'label' => 'Project root writable (.env)',
            'ok' => $rootWritable,
            'detail' => $rootWritable
                ? $projectRoot
                : 'not writable — use manual File Manager setup (see docs/setup.md)',
        ];
        $storage = rtrim($projectRoot, '/') . '/storage';
        $storageWritable = is_dir($storage) && is_writable($storage);
        $checks[] = [
            'label' => 'storage/ writable (lock, progress, logs)',
            'ok' => $storageWritable,
            'detail' => $storageWritable ? $storage : 'not writable — fix permissions via File Manager',
        ];
        return $checks;
    }

    /** @param array<int,array{label:string,ok:bool,detail:string}> $checks */
    public static function allOk(array $checks): bool
    {
        foreach ($checks as $c) {
            if (!$c['ok']) {
                return false;
            }
        }
        return true;
    }
}
