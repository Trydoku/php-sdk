<?php

declare(strict_types=1);

namespace Trydoku\Tests\Unit\HttpClient;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Trydoku\Config;
use Trydoku\Exception\ApiException;
use Trydoku\Exception\AuthenticationException;
use Trydoku\Exception\AuthorizationException;
use Trydoku\Exception\BatchNotReadyException;
use Trydoku\Exception\ConflictException;
use Trydoku\Exception\GenerationSetupFailedException;
use Trydoku\Exception\IdempotencyKeyConflictException;
use Trydoku\Exception\InsufficientCreditsException;
use Trydoku\Exception\PayloadTooLargeException;
use Trydoku\Exception\UnsupportedMediaTypeException;
use Trydoku\Exception\ValidationException;
use Trydoku\HttpClient\AuthenticatedClient;

final class AuthenticatedClientTest extends TestCase
{
    private function createClient(ClientInterface $mockPsr18): AuthenticatedClient
    {
        $factory = new HttpFactory();

        return new AuthenticatedClient(
            httpClient: $mockPsr18,
            requestFactory: $factory,
            streamFactory: $factory,
            config: new Config(apiToken: 'test-token', baseUrl: 'https://api.test/v1'),
        );
    }

    private function mockHttpClient(Response $response): ClientInterface
    {
        $mock = $this->createMock(ClientInterface::class);
        $mock->method('sendRequest')->willReturn($response);

        return $mock;
    }

    public function testSuccessfulJsonRequest(): void
    {
        $body = json_encode(['data' => ['id' => 'batch-123']]);
        $client = $this->createClient($this->mockHttpClient(new Response(200, [], $body)));

        $result = $client->request('GET', '/batches/batch-123');

        $this->assertSame('batch-123', $result['data']['id']);
    }

    public function testBearerTokenIsSent(): void
    {
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function (RequestInterface $request): bool {
                return $request->getHeaderLine('Authorization') === 'Bearer test-token'
                    && $request->getHeaderLine('Accept') === 'application/json';
            }))
            ->willReturn(new Response(200, [], '{}'));

        $client = $this->createClient($mockHttp);
        $client->request('GET', '/test');
    }

    public function testJsonBodyIsSent(): void
    {
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function (RequestInterface $request): bool {
                $body = json_decode((string) $request->getBody(), true);

                return $request->getHeaderLine('Content-Type') === 'application/json'
                    && $body['template_uuid'] === 'test-uuid';
            }))
            ->willReturn(new Response(201, [], json_encode(['data' => ['id' => 'b1']])));

        $client = $this->createClient($mockHttp);
        $client->request('POST', '/generate', ['template_uuid' => 'test-uuid', 'data' => []]);
    }

    public function testRawRequest(): void
    {
        $zipContent = 'PK-zip-binary-data';
        $client = $this->createClient($this->mockHttpClient(new Response(200, [], $zipContent)));

        $result = $client->requestRaw('GET', '/batches/b1/zip');

        $this->assertSame($zipContent, $result);
    }

    public function test401ThrowsAuthenticationException(): void
    {
        $body = json_encode(['message' => 'Unauthenticated.']);
        $client = $this->createClient($this->mockHttpClient(new Response(401, [], $body)));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Unauthenticated.');

        $client->request('GET', '/test');
    }

    public function test402ThrowsInsufficientCreditsException(): void
    {
        $body = json_encode([
            'message' => 'Not enough credits.',
            'credits_available' => 5,
            'credits_required' => 10,
        ]);
        $client = $this->createClient($this->mockHttpClient(new Response(402, [], $body)));

        try {
            $client->request('POST', '/generate', ['data' => []]);
            $this->fail('Expected InsufficientCreditsException');
        } catch (InsufficientCreditsException $e) {
            $this->assertSame(5.0, $e->creditsAvailable);
            $this->assertSame(10.0, $e->creditsRequired);
            $this->assertSame(402, $e->httpStatusCode);
        }
    }

    public function test403ThrowsAuthorizationException(): void
    {
        $body = json_encode(['message' => 'Forbidden.']);
        $client = $this->createClient($this->mockHttpClient(new Response(403, [], $body)));

        $this->expectException(AuthorizationException::class);

        $client->request('GET', '/test');
    }

    public function test413ThrowsPayloadTooLargeException(): void
    {
        $body = json_encode(['message' => 'Too large.', 'error' => ['code' => 'PAYLOAD_TOO_LARGE', 'message' => 'Too large.', 'details' => []]]);
        $client = $this->createClient($this->mockHttpClient(new Response(413, [], $body)));

        $this->expectException(PayloadTooLargeException::class);

        $client->request('POST', '/generate', ['data' => []]);
    }

    public function test422ThrowsValidationException(): void
    {
        $body = json_encode([
            'message' => 'The given data was invalid.',
            'errors' => [
                'template_uuid' => ['The template uuid field is required.'],
                'data.0.invoice_date' => ['The invoice date does not match the format Y-m-d.'],
            ],
        ]);
        $client = $this->createClient($this->mockHttpClient(new Response(422, [], $body)));

        try {
            $client->request('POST', '/generate', ['data' => []]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertCount(2, $e->getErrors());
            $this->assertArrayHasKey('template_uuid', $e->errors);
            $this->assertArrayHasKey('data.0.invoice_date', $e->errors);
            $this->assertSame(422, $e->httpStatusCode);
        }
    }

    public function test400BatchNotReadyThrows(): void
    {
        $body = json_encode([
            'error' => ['code' => 'BATCH_NOT_READY', 'message' => 'Batch processing is not complete.', 'details' => ''],
            'message' => 'Batch processing is not complete.',
        ]);
        $client = $this->createClient($this->mockHttpClient(new Response(400, [], $body)));

        $this->expectException(BatchNotReadyException::class);

        $client->requestRaw('GET', '/batches/b1/zip');
    }

    public function test400GenericThrowsApiException(): void
    {
        $body = json_encode(['message' => 'Some other bad request.']);
        $client = $this->createClient($this->mockHttpClient(new Response(400, [], $body)));

        $this->expectException(ApiException::class);

        $client->request('GET', '/test');
    }

    public function test503GenerationSetupFailedThrows(): void
    {
        $body = json_encode([
            'error' => [
                'code' => 'GENERATION_SETUP_FAILED',
                'message' => 'Document generation setup failed and credits have been refunded.',
            ],
        ]);
        $client = $this->createClient($this->mockHttpClient(new Response(503, [], $body)));

        $this->expectException(GenerationSetupFailedException::class);

        $client->request('POST', '/generate', ['data' => []]);
    }

    public function test503GenericThrowsApiException(): void
    {
        $body = json_encode(['message' => 'Service temporarily unavailable.']);
        $client = $this->createClient($this->mockHttpClient(new Response(503, [], $body)));

        $this->expectException(ApiException::class);

        $client->request('GET', '/test');
    }

    public function testUnknownErrorCodeThrowsApiException(): void
    {
        $client = $this->createClient($this->mockHttpClient(new Response(500, [], '{"message":"Internal Server Error"}')));

        $this->expectException(ApiException::class);

        $client->request('GET', '/test');
    }

    public function testNonJsonErrorResponseThrowsApiException(): void
    {
        $client = $this->createClient($this->mockHttpClient(new Response(500, [], '<html>Error</html>')));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTP 500 error');

        $client->request('GET', '/test');
    }

    public function testExceptionCarriesResponseBody(): void
    {
        $body = '{"message":"Unauthenticated."}';
        $client = $this->createClient($this->mockHttpClient(new Response(401, [], $body)));

        try {
            $client->request('GET', '/test');
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame($body, $e->responseBody);
            $this->assertSame(401, $e->httpStatusCode);
        }
    }
    public function testMalformedErrorFieldsPreserveTypedExceptions(): void
    {
        foreach ([400, 401, 402, 403, 409, 413, 415, 422, 503] as $status) {
            $body = '{"message":[],"error":42,"errors":"invalid","credits_available":[],"credits_required":"unknown"}';
            $client = $this->createClient($this->mockHttpClient(new Response($status, [], $body)));

            try {
                $client->request('GET', '/test');
                $this->fail('Expected an API error.');
            } catch (\Trydoku\Exception\TrydokuException $exception) {
                $this->assertSame($status, $exception->httpStatusCode);
                $this->assertSame("HTTP {$status} error", $exception->getMessage());
                $this->assertSame($body, $exception->responseBody);
                if ($exception instanceof ValidationException) {
                    $this->assertSame([], $exception->errors);
                }
                if ($exception instanceof InsufficientCreditsException) {
                    $this->assertNull($exception->creditsAvailable);
                    $this->assertNull($exception->creditsRequired);
                }
            }
        }
    }

    public function testInvalidSuccessResponsesRetainResponseContext(): void
    {
        foreach (['<html>Error</html>', 'null', '[]', '"text"', ''] as $body) {
            $client = $this->createClient($this->mockHttpClient(new Response(200, [], $body)));

            try {
                $client->request('GET', '/test');
                $this->fail('Expected an invalid API response error.');
            } catch (ApiException $exception) {
                $this->assertSame(200, $exception->httpStatusCode);
                $this->assertSame($body, $exception->responseBody);
            }
        }
    }

    public function testValidationErrorsContainOnlyMessageLists(): void
    {
        $body = '{"errors":{"name":["Required",42,null],"email":"Invalid"}}';
        $client = $this->createClient($this->mockHttpClient(new Response(422, [], $body)));

        try {
            $client->request('POST', '/generate');
            $this->fail('Expected validation errors.');
        } catch (ValidationException $exception) {
            $this->assertSame(['name' => ['Required']], $exception->errors);
        }
    }

    public function test409ThrowsConflictException(): void
    {
        $body = json_encode([
            'error' => ['code' => 'IDEMPOTENCY_IN_PROGRESS', 'message' => 'This Idempotency-Key is already in progress.'],
            'message' => 'This Idempotency-Key is already in progress.',
        ]);
        $client = $this->createClient($this->mockHttpClient(new Response(409, [], $body)));

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('This Idempotency-Key is already in progress.');

        $client->request('POST', '/generate', ['data' => []]);
    }

    public function test415ThrowsUnsupportedMediaTypeException(): void
    {
        $body = json_encode([
            'error' => ['code' => 'UNSUPPORTED_CONTENT_ENCODING', 'message' => 'Content-Encoding must be identity.'],
            'message' => 'Content-Encoding must be identity.',
        ]);
        $client = $this->createClient($this->mockHttpClient(new Response(415, [], $body)));

        $this->expectException(UnsupportedMediaTypeException::class);

        $client->request('POST', '/generate', ['data' => []]);
    }

    public function test422IdempotencyKeyConflictThrowsDedicatedException(): void
    {
        $body = json_encode([
            'error' => [
                'code' => 'IDEMPOTENCY_KEY_CONFLICT',
                'message' => 'This Idempotency-Key was reused with a different body.',
            ],
            'message' => 'This Idempotency-Key was reused with a different body.',
        ]);
        $client = $this->createClient($this->mockHttpClient(new Response(422, [], $body)));

        $this->expectException(IdempotencyKeyConflictException::class);
        $this->expectExceptionMessage('This Idempotency-Key was reused with a different body.');

        $client->request('POST', '/generate', ['data' => []]);
    }

}
