<?php
declare(strict_types=1);

/**
 * Safe SQL statement splitter for migration/seed files.
 * Handles single/double/backtick quotes, line (--) and (#) and block comments,
 * without naive explode(';') breaking string literals.
 * Does NOT implement DELIMITER routines; migration files must not use custom DELIMITERs.
 * Returns non-empty trimmed statements (without trailing semicolon).
 */
final class SqlSplitter
{
    /** @return array<int,string> */
    public static function split(string $sql): array
    {
        $stmts = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        while ($i < $len) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // Inside line comment: consume until newline (keep newline as separator whitespace)
            if ($inLineComment) {
                if ($c === "\n") {
                    $inLineComment = false;
                    $buf .= $c;
                }
                $i++;
                continue;
            }
            // Inside block comment: consume until */
            if ($inBlockComment) {
                if ($c === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i += 2;
                } else {
                    $i++;
                }
                continue;
            }
            // Enter comments only when not in quotes
            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($c === '-' && $next === '-' && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\n" || $sql[$i + 2] === "\r")) {
                    $inLineComment = true;
                    $i += 2;
                    continue;
                }
                if ($c === '#') {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
                if ($c === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i += 2;
                    continue;
                }
            }

            // Quote handling with backslash escapes (MySQL style)
            if ($c === "'" && !$inDouble && !$inBacktick) {
                // Count preceding backslashes to detect escaped quote
                $bs = 0;
                $j = $i - 1;
                while ($j >= 0 && $sql[$j] === '\\') {
                    $bs++;
                    $j--;
                }
                if ($bs % 2 === 0) {
                    // Handle '' escaped quote inside single-quoted string
                    if ($inSingle && $next === "'") {
                        $buf .= $c . $next;
                        $i += 2;
                        continue;
                    }
                    $inSingle = !$inSingle;
                }
                $buf .= $c;
                $i++;
                continue;
            }
            if ($c === '"' && !$inSingle && !$inBacktick) {
                $bs = 0;
                $j = $i - 1;
                while ($j >= 0 && $sql[$j] === '\\') {
                    $bs++;
                    $j--;
                }
                if ($bs % 2 === 0) {
                    $inDouble = !$inDouble;
                }
                $buf .= $c;
                $i++;
                continue;
            }
            if ($c === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
                $buf .= $c;
                $i++;
                continue;
            }

            // Statement terminator only outside quotes/comments
            if ($c === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trimmed = trim($buf);
                if ($trimmed !== '') {
                    $stmts[] = $trimmed;
                }
                $buf = '';
                $i++;
                continue;
            }

            $buf .= $c;
            $i++;
        }
        $trimmed = trim($buf);
        if ($trimmed !== '') {
            $stmts[] = $trimmed;
        }
        return $stmts;
    }
}
