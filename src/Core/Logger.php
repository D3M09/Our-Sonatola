<?php
declare(strict_types=1);

/** Structured file logger. Never logs passwords, tokens, or PII beyond IDs. */
final class Logger
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
    }

    public static function default(): self
    {
        $root = dirname(__DIR__, 3);
        return new self($root . '/storage/logs/app.log');
    }

    /** @param array<string,mixed> $ctx */
    public function info(string $msg, array $ctx = []): void
    {
        $this->write('INFO', $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public function warning(string $msg, array $ctx = []): void
    {
        $this->write('WARNING', $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public function error(string $msg, array $ctx = []): void
    {
        $this->write('ERROR', $msg, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function write(string $level, string $msg, array $ctx): void
    {
        // Scrub sensitive keys if a caller accidentally passes them
        foreach (['password', 'password_hash', 'token', 'token_hash', 'db_pass', 'DB_PASS'] as $k) {
            unset($ctx[$k]);
        }
        $line = sprintf(
            "[%s] %s %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            $msg,
            $ctx !== [] ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
