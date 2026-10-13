<?php
declare(strict_types=1);

/** Tiny test helper: pass/fail counters, skip support. */
final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var array<int,string> */
    public static array $failures = [];
    /** @var array<int,string> */
    public static array $skipped = [];

    public static function ok(bool $cond, string $name): void
    {
        if ($cond) {
            self::$pass++;
            echo "  PASS {$name}\n";
        } else {
            self::$fail++;
            self::$failures[] = $name;
            echo "  FAIL {$name}\n";
        }
    }

    public static function skip(string $name, string $reason): void
    {
        self::$skipped[] = "{$name} ({$reason})";
        echo "  SKIP {$name} — {$reason}\n";
    }
}
