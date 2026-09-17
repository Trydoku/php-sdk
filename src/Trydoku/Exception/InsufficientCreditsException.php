<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * HTTP 402 — The account does not have enough credits.
 */
class InsufficientCreditsException extends TrydokuException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $httpStatusCode = null,
        ?string $responseBody = null,
        public readonly ?float $creditsAvailable = null,
        public readonly ?float $creditsRequired = null,
    ) {
        parent::__construct($message, $code, $previous, $httpStatusCode, $responseBody);
    }
}
