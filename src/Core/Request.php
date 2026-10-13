<?php
declare(strict_types=1);

/** Minimal HTTP request wrapper (works for web + API). */
final class Request
{
    public string $method;
    public string $path;
    /** @var array<string,string> */
    public array $query;
    /** @var array<string,mixed> */
    public array $body;
    /** @var array<string,string> */
    public array $headers;
    /** @var array<string,mixed>|null */
    public ?array $user = null;

    /** @param array<string,string> $query @param array<string,mixed> $body @param array<string,string> $headers */
    public function __construct(string $method, string $path, array $query = [], array $body = [], array $headers = [])
    {
        $this->method = strtoupper($method);
        $this->path = '/' . ltrim($path, '/');
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $body = $_POST;
        $ct = $headers['content-type'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            // Enforce 2MB JSON body cap
            if (strlen($raw) > 2 * 1024 * 1024) {
                throw AppException::badRequest('Request body too large.');
            }
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw AppException::badRequest('Malformed JSON body.');
                }
                $body = $decoded;
            } else {
                $body = [];
            }
        }
        /** @var array<string,string> $q */
        $q = $_GET;
        /** @var array<string,string> $h */
        $h = $headers;
        /** @var array<string,mixed> $b */
        $b = is_array($body) ? $body : [];
        return new self((string) $method, (string) $path, $q, $b, $h);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        return $this->query[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $h = $this->headers['authorization'] ?? '';
        if (str_starts_with($h, 'Bearer ')) {
            $t = trim(substr($h, 7));
            return $t !== '' ? $t : null;
        }
        return null;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public function userAgent(): string
    {
        // Strip control characters (incl. CR/LF) so the value cannot forge
        // lines in the append-only log file or break audit-log consumers.
        $ua = preg_replace('/[\x00-\x1F\x7F]/', '', $_SERVER['HTTP_USER_AGENT'] ?? '');
        return substr((string) $ua, 0, 512);
    }
}
