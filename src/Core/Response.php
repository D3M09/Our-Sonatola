<?php
declare(strict_types=1);

/** Consistent JSON + HTML response helper. */
final class Response
{
    public static function json(mixed $data, int $status = 200, array $meta = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        $payload = ['success' => $status < 400, 'data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $errors */
    public static function error(string $message, int $status = 500, string $code = 'internal_error', array $errors = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        $payload = ['success' => false, 'error' => ['code' => $code, 'message' => $message]];
        if ($errors !== []) {
            $payload['error']['details'] = $errors;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function html(string $html, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $html;
    }

    public static function paginated(array $items, int $page, int $perPage, int $total): void
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        self::json($items, 200, [
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $pages,
            ],
        ]);
    }
}
