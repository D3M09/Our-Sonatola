<?php
declare(strict_types=1);
/** Test runner: php tests/run.php */
require_once __DIR__ . '/T.php';

$files = glob(__DIR__ . '/test_*.php') ?: [];
sort($files);
foreach ($files as $f) {
    echo "== " . basename($f) . " ==\n";
    require $f;
}
echo "\nRESULT: " . T::$pass . " passed, " . T::$fail . " failed, " . count(T::$skipped) . " skipped\n";
if (T::$failures !== []) {
    echo "FAILURES:\n";
    foreach (T::$failures as $f) {
        echo " - {$f}\n";
    }
    exit(1);
}
