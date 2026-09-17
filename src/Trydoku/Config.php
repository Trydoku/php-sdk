<?php

declare(strict_types=1);

namespace Trydoku;

/**
 * Immutable configuration for the TRYDOKU API client.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://www.trydoku.com/api/v1';

    /**
     * @param string $apiToken Personal access token from Account Settings → API Keys
     * @param string $baseUrl API root, including the `/v1` prefix
     */
    public function __construct(
        public readonly string $apiToken,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        if (trim($this->apiToken) === '') {
            throw new \InvalidArgumentException('API token must not be empty.');
        }
    }
}
