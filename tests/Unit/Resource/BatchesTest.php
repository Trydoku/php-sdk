<?php

declare(strict_types=1);

namespace Trydoku\Tests\Unit\Resource;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Trydoku\Config;
use Trydoku\DTO\Batch;
use Trydoku\Exception\ApiException;
use Trydoku\Exception\BatchNotReadyException;
use Trydoku\HttpClient\AuthenticatedClient;
use Trydoku\Resource\Batches;

final class BatchesTest extends TestCase
{
    private function createBatchesResource(ClientInterface $mockHttp): Batches
    {
        $factory = new HttpFactory();
        $authenticatedClient = new AuthenticatedClient(
            httpClient: $mockHttp,
            requestFactory: $factory,
            streamFactory: $factory,
            config: new Config(apiToken: 'test-token'),
        );

        return new Batches($authenticatedClient);
    }

    private function completedBatchJson(): string
    {
        return json_encode([
            'data' => [
                'id' => 'batch-123',
                'status' => 'completed',
                'setup_status' => 'ready',
                'total_items' => 2,
                'processed_items' => 2,
                'failed_items' => 0,
                'created_at' => '2026-07-16T18:20:00.000000Z',
                'updated_at' => '2026-07-16T18:20:05.000000Z',
                'links' => [
                    'self' => 'https://www.trydoku.com/api/v1/batches/batch-123',
                    'zip' => 'https://www.trydoku.com/api/v1/batches/batch-123/zip',
                ],
            ],
        ]);
    }

    public function testGetBatch(): void
    {
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->method('sendRequest')
            ->willReturn(new Response(200, [], $this->completedBatchJson()));

        $batches = $this->createBatchesResource($mockHttp);
        $batch = $batches->get('batch-123');

        $this->assertInstanceOf(Batch::class, $batch);
        $this->assertSame('batch-123', $batch->id);
        $this->assertTrue($batch->isCompleted());
        $this->assertNotNull($batch->links->zip);
    }

    public function testDownloadZip(): void
    {
        $zipBinary = 'PK-fake-zip-content';
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->method('sendRequest')
            ->willReturn(new Response(200, [], $zipBinary));

        $batches = $this->createBatchesResource($mockHttp);
        $content = $batches->downloadZip('batch-123');

        $this->assertSame($zipBinary, $content);
    }

    public function testDownloadZipThrowsBatchNotReady(): void
    {
        $body = json_encode([
            'error' => ['code' => 'BATCH_NOT_READY', 'message' => 'Batch processing is not complete.', 'details' => ''],
            'message' => 'Batch processing is not complete.',
        ]);

        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->method('sendRequest')
            ->willReturn(new Response(400, [], $body));

        $batches = $this->createBatchesResource($mockHttp);

        $this->expectException(BatchNotReadyException::class);

        $batches->downloadZip('batch-123');
    }

    public function testWaitForCompletionReturnsImmediately(): void
    {
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->once())
            ->method('sendRequest')
            ->willReturn(new Response(200, [], $this->completedBatchJson()));

        $batches = $this->createBatchesResource($mockHttp);
        $batch = $batches->waitForCompletion('batch-123', maxAttempts: 5, intervalMs: 10);

        $this->assertTrue($batch->isCompleted());
    }

    public function testWaitForCompletionPollsMultipleTimes(): void
    {
        $processingJson = json_encode([
            'data' => [
                'id' => 'batch-123',
                'status' => 'processing',
                'setup_status' => 'ready',
                'total_items' => 2,
                'processed_items' => 0,
                'failed_items' => 0,
                'created_at' => null,
                'updated_at' => null,
                'links' => ['self' => 'https://example.com/b/batch-123', 'zip' => null],
            ],
        ]);

        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->exactly(3))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], $processingJson),
                new Response(200, [], $processingJson),
                new Response(200, [], $this->completedBatchJson()),
            );

        $batches = $this->createBatchesResource($mockHttp);
        $batch = $batches->waitForCompletion('batch-123', maxAttempts: 5, intervalMs: 10);

        $this->assertTrue($batch->isCompleted());
    }

    public function testWaitForCompletionTimesOut(): void
    {
        $processingJson = json_encode([
            'data' => [
                'id' => 'batch-123',
                'status' => 'processing',
                'setup_status' => 'ready',
                'total_items' => 2,
                'processed_items' => 0,
                'failed_items' => 0,
                'created_at' => null,
                'updated_at' => null,
                'links' => ['self' => 'https://example.com/b/batch-123', 'zip' => null],
            ],
        ]);

        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->method('sendRequest')
            ->willReturn(new Response(200, [], $processingJson));

        $batches = $this->createBatchesResource($mockHttp);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('did not complete within 2 polling attempts');

        $batches->waitForCompletion('batch-123', maxAttempts: 2, intervalMs: 10);
    }

    public function testWaitForCompletionReturnsOnFailedItems(): void
    {
        $failedJson = json_encode([
            'data' => [
                'id' => 'batch-fail',
                'status' => 'completed',
                'setup_status' => 'ready',
                'total_items' => 2,
                'processed_items' => 1,
                'failed_items' => 1,
                'created_at' => null,
                'updated_at' => null,
                'links' => ['self' => 'https://example.com/b/batch-fail', 'zip' => null],
            ],
        ]);

        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->once())
            ->method('sendRequest')
            ->willReturn(new Response(200, [], $failedJson));

        $batches = $this->createBatchesResource($mockHttp);
        $batch = $batches->waitForCompletion('batch-fail', maxAttempts: 5, intervalMs: 10);

        $this->assertTrue($batch->hasFailed());
    }
    public function testRowFailureDoesNotStopProcessingBatch(): void
    {
        $processing = json_decode($this->completedBatchJson(), true);
        $processing['data']['status'] = 'processing';
        $processing['data']['processed_items'] = 0;
        $processing['data']['failed_items'] = 1;
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->exactly(2))->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], json_encode($processing)),
                new Response(200, [], $this->completedBatchJson()),
            );

        $batch = $this->createBatchesResource($mockHttp)->waitForCompletion('batch-123', intervalMs: 0);

        $this->assertTrue($batch->isCompleted());
    }

    public function testTerminalFailuresStopPollingWithoutFailedRows(): void
    {
        foreach ([['failed', 'ready'], ['processing', 'failed']] as [$status, $setupStatus]) {
            $response = json_decode($this->completedBatchJson(), true);
            $response['data']['status'] = $status;
            $response['data']['setup_status'] = $setupStatus;
            $mockHttp = $this->createMock(ClientInterface::class);
            $mockHttp->expects($this->once())->method('sendRequest')
                ->willReturn(new Response(200, [], json_encode($response)));

            $batch = $this->createBatchesResource($mockHttp)->waitForCompletion('batch-123', intervalMs: 0);

            $this->assertSame($status, $batch->status);
            $this->assertSame($setupStatus, $batch->setupStatus);
        }
    }

    public function testInvalidPollingArgumentsDoNotSendRequests(): void
    {
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->never())->method('sendRequest');
        $batches = $this->createBatchesResource($mockHttp);

        foreach ([[0, 0], [-1, 0], [1, -1], [1, PHP_INT_MAX]] as [$attempts, $interval]) {
            try {
                $batches->waitForCompletion('batch-123', $attempts, $interval);
                $this->fail('Expected invalid polling arguments.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function testBatchIdsAreEncodedAsSinglePathSegments(): void
    {
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->exactly(2))->method('sendRequest')
            ->with($this->callback(function (RequestInterface $request): bool {
                $this->assertSame('', $request->getUri()->getQuery());
                $this->assertSame('', $request->getUri()->getFragment());

                return str_contains($request->getUri()->getPath(), '/batches/id%2Fpart%3Fquery%23fragment');
            }))
            ->willReturn(new Response(200, [], $this->completedBatchJson()));
        $batches = $this->createBatchesResource($mockHttp);

        $batches->get('id/part?query#fragment');
        $batches->downloadZip('id/part?query#fragment');
    }

    public function testWaitForCompletionContinuesWhileSetupPending(): void
    {
        $pending = json_decode($this->completedBatchJson(), true);
        $pending['data']['status'] = 'processing';
        $pending['data']['setup_status'] = 'pending';
        $pending['code'] = 'GENERATION_SETUP_PENDING';

        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->exactly(2))->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                new Response(202, [], json_encode($pending)),
                new Response(200, [], $this->completedBatchJson()),
            );

        $batch = $this->createBatchesResource($mockHttp)->waitForCompletion('batch-123', intervalMs: 0);

        $this->assertTrue($batch->isCompleted());
        $this->assertFalse($batch->isSetupPending());
    }

    public function testGetIncompletePayloadThrowsApiException(): void
    {
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->method('sendRequest')->willReturn(new Response(200, [], '{"message":"ok"}'));
        $batches = $this->createBatchesResource($mockHttp);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The API response is missing a batch object.');

        $batches->get('batch-123');
    }

}
