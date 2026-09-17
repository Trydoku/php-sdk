<?php

declare(strict_types=1);

namespace Trydoku\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use Trydoku\DTO\Batch;
use Trydoku\DTO\BatchItem;
use Trydoku\DTO\BatchLinks;

final class BatchTest extends TestCase
{
    private function sampleBatchArray(): array
    {
        return [
            'id' => 'a91b22e1-a714-4113-a83d-bc350284e300',
            'status' => 'completed',
            'setup_status' => 'ready',
            'total_items' => 2,
            'processed_items' => 2,
            'failed_items' => 0,
            'created_at' => '2026-07-16T18:20:00.000000Z',
            'updated_at' => '2026-07-16T18:20:05.000000Z',
            'links' => [
                'self' => 'https://www.trydoku.com/api/v1/batches/a91b22e1-a714-4113-a83d-bc350284e300',
                'zip' => 'https://www.trydoku.com/api/v1/batches/a91b22e1-a714-4113-a83d-bc350284e300/zip',
            ],
            'items' => [
                [
                    'row_index' => 0,
                    'status' => 'completed',
                    'download_url' => 'https://example.com/doc1.docx',
                    'error' => null,
                ],
                [
                    'row_index' => 1,
                    'status' => 'completed',
                    'download_url' => 'https://example.com/doc2.docx',
                    'error' => null,
                ],
            ],
        ];
    }

    public function testFromArray(): void
    {
        $batch = Batch::fromArray($this->sampleBatchArray());

        $this->assertSame('a91b22e1-a714-4113-a83d-bc350284e300', $batch->id);
        $this->assertSame('completed', $batch->status);
        $this->assertSame('ready', $batch->setupStatus);
        $this->assertSame(2, $batch->totalItems);
        $this->assertSame(2, $batch->processedItems);
        $this->assertSame(0, $batch->failedItems);
        $this->assertInstanceOf(\DateTimeImmutable::class, $batch->createdAt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $batch->updatedAt);
        $this->assertInstanceOf(BatchLinks::class, $batch->links);
        $this->assertCount(2, $batch->items);
        $this->assertInstanceOf(BatchItem::class, $batch->items[0]);
    }

    public function testFromArrayWithoutItems(): void
    {
        $data = $this->sampleBatchArray();
        unset($data['items']);

        $batch = Batch::fromArray($data);

        $this->assertCount(0, $batch->items);
    }

    public function testFromArrayWithNullTimestamps(): void
    {
        $data = $this->sampleBatchArray();
        $data['created_at'] = null;
        $data['updated_at'] = null;

        $batch = Batch::fromArray($data);

        $this->assertNull($batch->createdAt);
        $this->assertNull($batch->updatedAt);
    }

    public function testIsCompleted(): void
    {
        $batch = Batch::fromArray($this->sampleBatchArray());

        $this->assertTrue($batch->isCompleted());
        $this->assertFalse($batch->isProcessing());
    }

    public function testIsProcessing(): void
    {
        $data = $this->sampleBatchArray();
        $data['status'] = 'processing';

        $batch = Batch::fromArray($data);

        $this->assertTrue($batch->isProcessing());
        $this->assertFalse($batch->isCompleted());
    }

    public function testHasFailed(): void
    {
        $data = $this->sampleBatchArray();
        $data['failed_items'] = 1;

        $batch = Batch::fromArray($data);

        $this->assertTrue($batch->hasFailed());
    }

    public function testHasNotFailed(): void
    {
        $batch = Batch::fromArray($this->sampleBatchArray());

        $this->assertFalse($batch->hasFailed());
    }

    public function testFromApiResponseReadsPendingCode(): void
    {
        $batch = Batch::fromApiResponse([
            'data' => $this->sampleBatchArray(),
            'code' => 'GENERATION_SETUP_PENDING',
        ]);

        $this->assertTrue($batch->isSetupPending());
        $this->assertSame('GENERATION_SETUP_PENDING', $batch->code);
    }

    public function testIsSetupPendingFromSetupStatus(): void
    {
        $data = $this->sampleBatchArray();
        $data['status'] = 'processing';
        $data['setup_status'] = 'pending';

        $batch = Batch::fromArray($data);

        $this->assertTrue($batch->isSetupPending());
        $this->assertFalse($batch->isCompleted());
    }

    public function testFromApiResponseMissingDataThrowsApiException(): void
    {
        $this->expectException(\Trydoku\Exception\ApiException::class);
        $this->expectExceptionMessage('The API response is missing a batch object.');

        Batch::fromApiResponse(['ok' => true]);
    }

    public function testFromArrayMissingIdThrows(): void
    {
        $data = $this->sampleBatchArray();
        unset($data['id']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"id"');

        Batch::fromArray($data);
    }

    public function testFromArrayInvalidDateThrows(): void
    {
        $data = $this->sampleBatchArray();
        $data['created_at'] = 'not-a-date';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"created_at"');

        Batch::fromArray($data);
    }

    public function testFromArrayInvalidItemThrows(): void
    {
        $data = $this->sampleBatchArray();
        $data['items'] = ['not-an-object'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items[0]');

        Batch::fromArray($data);
    }

    public function testFromArrayMissingLinksThrows(): void
    {
        $data = $this->sampleBatchArray();
        unset($data['links']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"links"');

        Batch::fromArray($data);
    }
}
