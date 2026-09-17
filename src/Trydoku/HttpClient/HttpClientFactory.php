<?php

declare(strict_types=1);

namespace Trydoku\HttpClient;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Trydoku\Config;

/**
 * Builds an AuthenticatedClient from explicit PSR instances, or via auto-discovery.
 *
 * @internal
 */
final class HttpClientFactory
{
    /**
     * @param ClientInterface|null $httpClient PSR-18 client; discovered automatically when omitted
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory; discovered when omitted
     * @param StreamFactoryInterface|null $streamFactory PSR-17 stream factory; discovered when omitted
     */
    public static function create(
        Config $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): AuthenticatedClient {
        return new AuthenticatedClient(
            httpClient: $httpClient ?? Psr18ClientDiscovery::find(),
            requestFactory: $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            streamFactory: $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            config: $config,
        );
    }
}
