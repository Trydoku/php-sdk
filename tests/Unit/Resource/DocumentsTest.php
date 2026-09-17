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
use Trydoku\HttpClient\AuthenticatedClient;
use Trydoku\Resource\Documents;

final class DocumentsTest extends TestCase
{
    private function createDocumentsResource(Response $response): Documents
    {
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->method('sendRequest')->willReturn($response);

        $factory = new HttpFactory();
        $authenticatedClient = new AuthenticatedClient(
            httpClient: $mockHttp,
            requestFactory: $factory,
            streamFactory: $factory,
            config: new Config(apiToken: 'test-token'),
        );

        return new Documents($authenticatedClient);
    }

    public function testGenerateWithTemplateUuid(): void
    {
        $responseBody = json_encode([
            'data' => [
                'id' => 'batch-abc',
                'status' => 'processing',
                'setup_status' => 'ready',
                'total_items' => 2,
                'processed_items' => 0,
                'failed_items' => 0,
                'created_at' => '2026-07-16T18:20:00.000000Z',
                'updated_at' => '2026-07-16T18:20:00.000000Z',
                'links' => [
                    'self' => 'https://www.trydoku.com/api/v1/batches/batch-abc',
                    'zip' => null,
                ],
            ],
        ]);

        $documents = $this->createDocumentsResource(new Response(201, [], $responseBody));

        $batch = $documents->generate(
            templateUuid: 'e81d77a2-f674-4b53-a8ee-bf350284e311',
            data: [
                ['Client_Name' => 'Acme', 'Amount' => '1250.00'],
                ['Client_Name' => 'Globex', 'Amount' => '3400.00'],
            ],
        );

        $this->assertInstanceOf(Batch::class, $batch);
        $this->assertSame('batch-abc', $batch->id);
        $this->assertSame('processing', $batch->status);
        $this->assertSame(2, $batch->totalItems);
    }

    public function testGenerateWithBase64Template(): void
    {
        $responseBody = json_encode([
            'data' => [
                'id' => 'batch-xyz',
                'status' => 'processing',
                'setup_status' => 'ready',
                'total_items' => 1,
                'processed_items' => 0,
                'failed_items' => 0,
                'created_at' => null,
                'updated_at' => null,
                'links' => ['self' => 'https://example.com/b/batch-xyz', 'zip' => null],
            ],
        ]);

        $documents = $this->createDocumentsResource(new Response(201, [], $responseBody));

        $batch = $documents->generate(
            templateBase64: base64_encode('fake-docx-content'),
            data: [['Name' => 'Test']],
            format: 'zip',
        );

        $this->assertSame('batch-xyz', $batch->id);
    }

    public function testGenerateWithVariableMapping(): void
    {
        $responseBody = json_encode([
            'data' => [
                'id' => 'batch-map',
                'status' => 'processing',
                'setup_status' => 'ready',
                'total_items' => 1,
                'processed_items' => 0,
                'failed_items' => 0,
                'created_at' => null,
                'updated_at' => null,
                'links' => ['self' => 'https://example.com/b/batch-map', 'zip' => null],
            ],
        ]);

        $documents = $this->createDocumentsResource(new Response(201, [], $responseBody));

        $batch = $documents->generate(
            templateUuid: 'uuid',
            data: [['source_field' => 'value']],
            variableMapping: ['source_field' => 'Template_Placeholder'],
        );

        $this->assertSame('batch-map', $batch->id);
    }
    public function testConflictingTemplateSourcesAreRejectedBeforeSending(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('sendRequest');
        $factory = new HttpFactory();
        $documents = new Documents(new AuthenticatedClient(
            $httpClient,
            $factory,
            $factory,
            new Config('test-token'),
        ));

        $this->expectException(\InvalidArgumentException::class);

        $documents->generate(templateUuid: 'uuid', templateBase64: 'base64');
    }

    public function testMissingTemplateSourceIsRejectedBeforeSending(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('sendRequest');
        $factory = new HttpFactory();
        $documents = new Documents(new AuthenticatedClient(
            $httpClient,
            $factory,
            $factory,
            new Config('test-token'),
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provide either a template UUID or a Base64 template.');

        $documents->generate(data: [['Name' => 'Test']]);
    }

    public function testWhitespaceOnlyTemplateIsTreatedAsMissing(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('sendRequest');
        $factory = new HttpFactory();
        $documents = new Documents(new AuthenticatedClient(
            $httpClient,
            $factory,
            $factory,
            new Config('test-token'),
        ));

        $this->expectException(\InvalidArgumentException::class);

        $documents->generate(templateUuid: '   ', data: [['Name' => 'Test']]);
    }

    public function testIdempotencyKeyIsSentAsHeader(): void
    {
        $responseBody = json_encode([
            'data' => [
                'id' => 'batch-idemp',
                'status' => 'processing',
                'setup_status' => 'ready',
                'total_items' => 1,
                'processed_items' => 0,
                'failed_items' => 0,
                'created_at' => null,
                'updated_at' => null,
                'links' => ['self' => 'https://example.com/b/batch-idemp', 'zip' => null],
            ],
        ]);

        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function (RequestInterface $request): bool {
                return $request->getHeaderLine('Idempotency-Key') === 'req-01JABC2DEFG3HIJK4LMNOPQRST'
                    && !str_contains((string) $request->getBody(), 'idempotency');
            }))
            ->willReturn(new Response(201, [], $responseBody));

        $factory = new HttpFactory();
        $documents = new Documents(new AuthenticatedClient(
            $mockHttp,
            $factory,
            $factory,
            new Config('test-token'),
        ));

        $batch = $documents->generate(
            templateUuid: 'uuid',
            data: [['Name' => 'Test']],
            idempotencyKey: 'req-01JABC2DEFG3HIJK4LMNOPQRST',
        );

        $this->assertSame('batch-idemp', $batch->id);
    }

    public function testInvalidIdempotencyKeyIsRejectedBeforeSending(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('sendRequest');
        $factory = new HttpFactory();
        $documents = new Documents(new AuthenticatedClient(
            $httpClient,
            $factory,
            $factory,
            new Config('test-token'),
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Idempotency-Key');

        $documents->generate(
            templateUuid: 'uuid',
            data: [['Name' => 'Test']],
            idempotencyKey: 'not a valid key!',
        );
    }

    public function testSetupPendingResponseIsExposedOnBatch(): void
    {
        $responseBody = json_encode([
            'data' => [
                'id' => 'batch-pending',
                'status' => 'processing',
                'setup_status' => 'pending',
                'total_items' => 1,
                'processed_items' => 0,
                'failed_items' => 0,
                'created_at' => null,
                'updated_at' => null,
                'links' => ['self' => 'https://example.com/b/batch-pending', 'zip' => null],
            ],
            'code' => 'GENERATION_SETUP_PENDING',
        ]);

        $documents = $this->createDocumentsResource(new Response(202, [], $responseBody));
        $batch = $documents->generate(
            templateUuid: 'uuid',
            data: [['Name' => 'Test']],
        );

        $this->assertTrue($batch->isSetupPending());
        $this->assertSame('GENERATION_SETUP_PENDING', $batch->code);
        $this->assertFalse($batch->isCompleted());
    }

    public function testIncompleteBatchPayloadThrowsApiException(): void
    {
        $documents = $this->createDocumentsResource(new Response(201, [], '{"ok":true}'));

        $this->expectException(\Trydoku\Exception\ApiException::class);
        $this->expectExceptionMessage('The API response is missing a batch object.');

        $documents->generate(templateUuid: 'uuid', data: [['Name' => 'Test']]);
    }

}
