<?php

declare(strict_types=1);

namespace SilverShop\Api;

use RuntimeException;
use Throwable;

/**
 * Thrown by resources/serializers to signal an HTTP error. The {@link Control\ApiController} catches it and renders a
 * negotiated error response with the carried status code, so resource code can fail with a plain throw.
 */
class ApiException extends RuntimeException
{
    private int $statusCode;

    public function __construct(int $statusCode, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, $message);
    }

    public static function badRequest(string $message = 'Bad request'): self
    {
        return new self(400, $message);
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(403, $message);
    }

    public static function methodNotAllowed(string $message = 'Method not allowed'): self
    {
        return new self(405, $message);
    }
}
