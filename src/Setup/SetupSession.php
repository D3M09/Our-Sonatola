<?php
declare(strict_types=1);

/**
 * Wizard session state. Holds progress + non-secret connection facts only.
 * The database PASSWORD is never stored here (see step handlers: it is
 * supplied per POST, used within that request, then unset). Idle sessions
 * expire after IDLE_SECONDS and wipe all wizard state.
 */
final class SetupSession
{
    public const IDLE_SECONDS = 600; // approved 10-minute wizard timeout
    private const KEY = 'sonatola_setup';

    /** @return array<string,mixed> */
    public static function get(): array
    {
        // Operates on $_SESSION directly (same convention as Csrf) so the
        // wizard works wherever the caller has started a session; an
        // unstarted session simply yields no state.
        $s = $_SESSION[self::KEY] ?? null;
        return is_array($s) ? $s : [];
    }

    public static function init(): void
    {
        $_SESSION[self::KEY] = [
            'created_at' => time(),
            'last_activity' => time(),
            'csrf' => bin2hex(random_bytes(32)),
            'step' => 'requirements',
            'db' => null, // [host,port,name,user,charset] — never the password
            'progress' => [], // stage => true, mirrors storage/setup-progress.json
        ];
    }

    /** Returns false when expired (state wiped) or absent. Slides activity on success. */
    public static function touch(): bool
    {
        $s = self::get();
        if ($s === []) {
            return false;
        }
        $last = (int) ($s['last_activity'] ?? 0);
        if ($last <= 0 || (time() - $last) > self::IDLE_SECONDS) {
            self::clear();
            return false;
        }
        $_SESSION[self::KEY]['last_activity'] = time();
        return true;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    public static function csrfToken(): string
    {
        $s = self::get();
        if (empty($s['csrf'])) {
            self::init();
            $s = self::get();
        }
        return (string) ($s['csrf'] ?? '');
    }

    public static function validateCsrf(?string $supplied): bool
    {
        $expected = (string) (self::get()['csrf'] ?? '');
        if ($expected === '' || $supplied === null || $supplied === '') {
            return false;
        }
        return hash_equals($expected, $supplied);
    }

    /** @param array<string,string> $dbFacts host/port/name/user/charset (no password) */
    public static function setDbFacts(array $dbFacts): void
    {
        $_SESSION[self::KEY]['db'] = [
            'host' => $dbFacts['host'] ?? '',
            'port' => $dbFacts['port'] ?? '',
            'name' => $dbFacts['name'] ?? '',
            'user' => $dbFacts['user'] ?? '',
            'charset' => $dbFacts['charset'] ?? 'utf8mb4',
        ];
    }

    public static function markProgress(string $stage): void
    {
        $_SESSION[self::KEY]['progress'][$stage] = true;
    }
}
