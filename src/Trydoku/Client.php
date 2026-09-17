<?php

declare(strict_types=1);

namespace Trydoku;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Trydoku\HttpClient\AuthenticatedClient;
use Trydoku\HttpClient\HttpClientFactory;
use Trydoku\Resource\Batches;
use Trydoku\Resource\Documents;

/**
 * Main entry point for the TRYDOKU API.
 *
 * Usage:
 *
 *   // Minimal — discovers an installed PSR-18 HTTP client
 *   $client = new \Trydoku\Client('your-api-token');
 *
 *   // Custom base URL (for staging or a proxy)
 *   $client = new \Trydoku\Client(new \Trydoku\Config(
 *       apiToken: 'your-api-token',
 *       baseUrl: 'https://www.trydoku.com/api/v1',
 *   ));
 *
 *   // Explicit PSR-18 client (for example Symfony HttpClient)
 *   $client = new \Trydoku\Client('your-api-token', httpClient: $symfonyPsr18Client);
 *
 * @see https://www.trydoku.com/docs/api
 */
final class Client
{
    private readonly AuthenticatedClient $authenticatedClient;
    private ?Documents $documents = null;
    private ?Batches $batches = null;

    /**
     * @param string|Config $config API token string, or a Config object
     * @param ClientInterface|null $httpClient PSR-18 client; discovered automatically when omitted
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory; discovered when omitted
     * @param StreamFactoryInterface|null $streamFactory PSR-17 stream factory; discovered when omitted
     */
    public function __construct(
        string|Config $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if (is_string($config)) {
            $config = new Config(apiToken: $config);
        }

        $this->authenticatedClient = HttpClientFactory::create(
            config: $config,
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
        );
    }

    /**
     * Start document generation batches (`POST /v1/generate`).
     */
    public function documents(): Documents
    {
        return $this->documents ??= new Documents($this->authenticatedClient);
    }

    /**
     * Inspect batches and download results (`GET /v1/batches/*`).
     */
    public function batches(): Batches
    {
        return $this->batches ??= new Batches($this->authenticatedClient);
    }
}
