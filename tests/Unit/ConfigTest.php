<?php

declare(strict_types=1);

namespace Trydoku\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trydoku\Config;

final class ConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new Config(apiToken: 'test-token');

        $this->assertSame('test-token', $config->apiToken);
        $this->assertSame(Config::DEFAULT_BASE_URL, $config->baseUrl);
    }

    public function testCustomValues(): void
    {
        $config = new Config(
            apiToken: 'custom-token',
            baseUrl: 'https://custom.api/v1',
        );

        $this->assertSame('custom-token', $config->apiToken);
        $this->assertSame('https://custom.api/v1', $config->baseUrl);
    }

    public function testEmptyTokenThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API token must not be empty');

        new Config(apiToken: '');
    }

    public function testWhitespaceOnlyTokenThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config(apiToken: '   ');
    }
}
