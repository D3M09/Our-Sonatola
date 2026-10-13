<?php
declare(strict_types=1);

/** Application exceptions with HTTP mapping. Safe messages only to clients. */
class AppException extends Exception
{
    public function __construct(
        string $message,
        private int $statusCode = 500,
        private string $errorCode = 'internal_error',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function badRequest(string $msg = 'Invalid request.'): self
    {
        return new self($msg, 400, 'bad_request');
    }

    public static function unauthorized(string $msg = 'Authentication required.'): self
    {
        return new self($msg, 401, 'unauthorized');
    }

    public static function forbidden(string $msg = 'You do not have permission.'): self
    {
        return new self($msg, 403, 'forbidden');
    }

    public static function notFound(string $msg = 'Not found.'): self
    {
        return new self($msg, 404, 'not_found');
    }

    public static function conflict(string $msg = 'Conflict.'): self
    {
        return new self($msg, 409, 'conflict');
    }

    public static function unprocessable(string $msg = 'Validation failed.'): self
    {
        return new self($msg, 422, 'validation_failed');
    }

    public static function rateLimited(string $msg = 'Too many requests.'): self
    {
        return new self($msg, 429, 'rate_limited');
    }
}
