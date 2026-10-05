<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * HTTP 422 — The request body failed validation.
 *
 * `$errors` maps field paths (for example `"data.0.invoice_date"`)
 * to lists of human-readable messages.
 */
class ValidationException extends TrydokuException
{
    /**
     * @param array<string, string[]> $errors
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $httpStatusCode = null,
        ?string $responseBody = null,
        public readonly array $errors = [],
        ?string $errorCode = null,
    ) {
        parent::__construct($message, $code, $previous, $httpStatusCode, $responseBody, $errorCode);
    }

    /**
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
