<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * Base class for TRYDOKU API errors and exhausted polling attempts.
 *
 * Transport failures, invalid local arguments, and JSON encoding errors
 * keep their original types (`ClientExceptionInterface`,
 * `InvalidArgumentException`, `JsonException`).
 */
abstract class TrydokuException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?int $httpStatusCode = null,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
