<?php

declare(strict_types=1);

namespace Trydoku\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use Http\Discovery\ClassDiscovery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Trydoku\Client;
use Trydoku\Config;
use Trydoku\Resource\Batches;
use Trydoku\Resource\Documents;

final class ClientTest extends TestCase
{
    public function testConstructWithStringToken(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);

        $client = new Client('test-token', httpClient: $httpClient);

        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConstructWithConfigObject(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $config = new Config(apiToken: 'test-token');

        $client = new Client($config, httpClient: $httpClient);

        $this->assertInstanceOf(Client::class, $client);
    }

    public function testExplicitDependenciesWorkWhenDiscoveryIsDisabled(): void
    {
        $strategies = iterator_to_array(ClassDiscovery::getStrategies());
        ClassDiscovery::setStrategies([]);
        try {
            $factory = new HttpFactory();
            $client = new Client('fake-token', $this->createMock(ClientInterface::class), $factory, $factory);
            $this->assertInstanceOf(Client::class, $client);
        } finally {
            ClassDiscovery::setStrategies($strategies);
        }
    }

    public function testDocumentsReturnsSameInstance(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $client = new Client('test-token', httpClient: $httpClient);

        $documents1 = $client->documents();
        $documents2 = $client->documents();

        $this->assertInstanceOf(Documents::class, $documents1);
        $this->assertSame($documents1, $documents2);
    }

    public function testBatchesReturnsSameInstance(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $client = new Client('test-token', httpClient: $httpClient);

        $batches1 = $client->batches();
        $batches2 = $client->batches();

        $this->assertInstanceOf(Batches::class, $batches1);
        $this->assertSame($batches1, $batches2);
    }
}
