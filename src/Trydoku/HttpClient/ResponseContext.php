<?php

declare(strict_types=1);

namespace Trydoku\HttpClient;

/** @internal Decoded response plus the HTTP details needed for schema errors. */
final class ResponseContext
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly int $httpStatusCode,
        public readonly string $responseBody,
        public readonly bool $itemsIsJsonObject = false,
        public readonly bool $responseBodyTruncated = false,
    ) {
    }
}
