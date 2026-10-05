<?php

declare(strict_types=1);

namespace Calmfox\SyliusPrzelewy24Plugin\Api;

/** Przelewy24 could not be reached, refused a request, or answered something unreadable. */
final class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
