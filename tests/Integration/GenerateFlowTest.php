<?php

declare(strict_types=1);

namespace Trydoku\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Trydoku\Client;
use Trydoku\DTO\Batch;

/**
 * Integration tests that call the live TRYDOKU API.
 *
 * Requires a valid API token (`TRYDOKU_API_TOKEN`) and a stored template UUID
 * (`TRYDOKU_TEMPLATE_UUID`).
 *
 * Run with:
 *   TRYDOKU_API_TOKEN=xxx TRYDOKU_TEMPLATE_UUID=yyy ./vendor/bin/phpunit --testsuite integration
 */
#[Group('integration')]
final class GenerateFlowTest extends TestCase
{
    private ?Client $client = null;
    private ?string $templateUuid = null;

    protected function setUp(): void
    {
        $token = getenv('TRYDOKU_API_TOKEN');
        $this->templateUuid = getenv('TRYDOKU_TEMPLATE_UUID') ?: null;

        if (!$token || !$this->templateUuid) {
            $this->markTestSkipped(
                'Integration tests require TRYDOKU_API_TOKEN and TRYDOKU_TEMPLATE_UUID environment variables.'
            );
        }

        $this->client = new Client($token);
    }

    public function testGenerateAndPollAndDownload(): void
    {
        // 1. Generate documents
        $batch = $this->client->documents()->generate(
            templateUuid: $this->templateUuid,
            data: [
                ['Client_Name' => 'Integration Test Corp', 'Invoice_Number' => 'INT-001'],
            ],
        );

        $this->assertInstanceOf(Batch::class, $batch);
        $this->assertNotEmpty($batch->id);
        $this->assertSame(1, $batch->totalItems);

        // 2. Wait for completion
        $completedBatch = $this->client->batches()->waitForCompletion(
            $batch->id,
            maxAttempts: 60,
            intervalMs: 2000,
        );

        $this->assertTrue($completedBatch->isCompleted());
        $this->assertSame(1, $completedBatch->processedItems);
        $this->assertSame(0, $completedBatch->failedItems);
        $this->assertNotNull($completedBatch->links->zip);

        // 3. Download ZIP
        $zipContent = $this->client->batches()->downloadZip($batch->id);

        $this->assertNotEmpty($zipContent);
        // ZIP files begin with the PK signature
        $this->assertStringStartsWith('PK', $zipContent);
    }
}
