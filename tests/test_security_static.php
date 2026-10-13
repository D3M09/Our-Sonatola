<?php
declare(strict_types=1);
// Upload policy + rate-limit + SQL file sanity (no live DB required)
require_once __DIR__ . '/../src/Core/AppException.php';

echo "upload_policy:\n";
// Only JPG/PNG/WEBP/PDF allowed — check service source allowlist
$src = (string) file_get_contents(__DIR__ . '/../src/Services/UploadService.php');
T::ok(str_contains($src, "'image/jpeg'") && str_contains($src, "'application/pdf'"), 'allowlist present');
T::ok(!str_contains($src, "'text/html'") && !str_contains($src, "'application/x-php'"), 'executables not allowed');
T::ok(str_contains($src, 'getimagesize') && str_contains($src, '%PDF'), 'magic-byte checks present');
T::ok(str_contains($src, 'storage/uploads') && str_contains($src, 'is_public'), 'private storage default');

echo "sql_sanity:\n";
$migs = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
T::ok(count($migs) >= 10, 'migrations count >= 10 (' . count($migs) . ')');
$all = '';
foreach ($migs as $f) {
    $all .= "\n" . file_get_contents($f);
}
T::ok(str_contains($all, 'ENGINE=InnoDB') && str_contains($all, 'utf8mb4'), 'innodb + utf8mb4');
T::ok(str_contains($all, 'FOREIGN KEY'), 'foreign keys present');
T::ok(str_contains($all, 'token_hash CHAR(64)') && !str_contains(strtolower($all), 'otp varchar'), 'tokens hashed, no plaintext otp column');
T::ok(preg_match('/\bDROP\s+TABLE\b/i', $all) !== 1, 'no destructive DROP in migrations');
T::ok(str_contains($all, 'applications') && str_contains((string) file_get_contents(__DIR__ . '/../database/seeds/002_settings.sql'), "'applications.online_enabled','0'"), 'online applications disabled by default');

$seeds = glob(__DIR__ . '/../database/seeds/*.sql') ?: [];
T::ok(count($seeds) >= 3, 'seeds count >= 3');
$seedAll = '';
foreach ($seeds as $f) {
    $seedAll .= (string) file_get_contents($f);
}
T::ok(!preg_match('/INSERT\s+INTO\s+users/i', $seedAll), 'no seeded users (no default admin)');
T::ok(str_contains($seedAll, 'super_admin') && str_contains($seedAll, 'citizen'), 'roles seeded');
