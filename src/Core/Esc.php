<?php
declare(strict_types=1);

/** Context-appropriate output escaping helper. */
final class Esc
{
    public static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
