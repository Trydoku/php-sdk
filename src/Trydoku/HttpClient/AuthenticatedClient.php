<?php

declare(strict_types=1);

namespace Trydoku\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Trydoku\Config;
use Trydoku\Exception\ApiException;
use Trydoku\Exception\AuthenticationException;
use Trydoku\Exception\AuthorizationException;
use Trydoku\Exception\BatchFilesMissingException;
use Trydoku\Exception\BatchNotReadyException;
use Trydoku\Exception\ConflictException;
use Trydoku\Exception\GenerationSetupFailedException;
use Trydoku\Exception\IdempotencyKeyConflictException;
use Trydoku\Exception\InsufficientCreditsException;
use Trydoku\Exception\PayloadTooLargeException;
use Trydoku\Exception\UnsupportedMediaTypeException;
use Trydoku\Exception\ValidationException;

/**
 * HTTP adapter that attaches a Bearer token, sets JSON headers,
 * and maps API error responses to typed exceptions.
 *
 * @internal
 */
final class AuthenticatedClient
{
    private const MAX_DIAGNOSTIC_BODY_BYTES = 65536;
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Config $config,
    ) {
    }

    /**
     * Send an authenticated JSON request and return the decoded object body.
     *
     * @param array<string, mixed>|null $body JSON request body, or null for no body
     * @param array<string, string> $headers Extra request headers
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException If the request body cannot be encoded
     * @throws \Trydoku\Exception\TrydokuException If the API returns an error or invalid JSON
     * @throws \Psr\Http\Client\ClientExceptionInterface On transport failure
     */
    public function request(string $method, string $uri, ?array $body = null, array $headers = []): array
    {
        return $this->requestWithContext($method, $uri, $body, $headers)->data;
    }

    /** @param array<string, mixed>|null $body @param array<string, string> $headers */
    public function requestWithContext(string $method, string $uri, ?array $body = null, array $headers = []): ResponseContext
    {
        $response = $this->sendRequest($method, $uri, $body, $headers);
        $responseBody = (string) $response->getBody();

        $this->throwOnError($response, $responseBody);

        try {
            $decoded = json_decode($responseBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiException(
                message: 'The API returned invalid JSON.',
                previous: $exception,
                httpStatusCode: $response->getStatusCode(),
                responseBody: $responseBody,
            );
        }

        if (!is_array($decoded) || !str_starts_with(ltrim($responseBody), '{')) {
            throw new ApiException(
                message: 'The API response must be a JSON object.',
                httpStatusCode: $response->getStatusCode(),
                responseBody: $responseBody,
            );
        }

        $shape = json_decode($responseBody);
        $itemsIsObject = isset($shape->data) && is_object($shape->data)
            && property_exists($shape->data, 'items') && is_object($shape->data->items);

        $truncated = strlen($responseBody) > self::MAX_DIAGNOSTIC_BODY_BYTES;
        $diagnosticBody = $truncated ? substr($responseBody, 0, self::MAX_DIAGNOSTIC_BODY_BYTES) : $responseBody;

        return new ResponseContext($decoded, $response->getStatusCode(), $diagnosticBody, $itemsIsObject, $truncated);
    }

    /**
     * Send an authenticated request and return the raw response body.
     *
     * Use this for binary downloads such as ZIP archives.
     *
     * @throws \Trydoku\Exception\TrydokuException If the API returns an error
     * @throws \Psr\Http\Client\ClientExceptionInterface On transport failure
     */
    public function requestRaw(string $method, string $uri): string
    {
        $response = $this->sendRequest($method, $uri);
        $responseBody = (string) $response->getBody();

        $this->throwOnError($response, $responseBody);

        return $responseBody;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function sendRequest(string $method, string $uri, ?array $body = null, array $headers = []): ResponseInterface
    {
        $url = rtrim($this->config->baseUrl, '/') . '/' . ltrim($uri, '/');
        $request = $this->requestFactory->createRequest($method, $url);

        $request = $request
            ->withHeader('Authorization', 'Bearer ' . $this->config->apiToken)
            ->withHeader('Accept', 'application/json');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $json = json_encode($body, \JSON_THROW_ON_ERROR);
            $stream = $this->streamFactory->createStream($json);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream);
        }

        return $this->httpClient->sendRequest($request);
    }

    private function throwOnError(ResponseInterface $response, string $responseBody): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        try {
            $decoded = json_decode($responseBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Non-JSON error bodies (HTML or plain text from proxies) still map by HTTP status.
            $decoded = [];
        }

        $decoded = is_array($decoded) ? $decoded : [];
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $message = $decoded['message'] ?? null;
        if (!is_string($message)) {
            $message = is_string($error['message'] ?? null) ? $error['message'] : "HTTP {$statusCode} error";
        }

        if ($statusCode === 402) {
            throw new InsufficientCreditsException(
                message: $message,
                httpStatusCode: $statusCode,
                responseBody: $responseBody,
                creditsAvailable: is_numeric($decoded['credits_available'] ?? null) ? (float) $decoded['credits_available'] : null,
                creditsRequired: is_numeric($decoded['credits_required'] ?? null) ? (float) $decoded['credits_required'] : null,
                errorCode: is_string($error['code'] ?? null) ? $error['code'] : null,
            );
        }

        if ($statusCode === 422 && ($error['code'] ?? null) === 'IDEMPOTENCY_KEY_CONFLICT') {
            throw new IdempotencyKeyConflictException(
                message: $message,
                httpStatusCode: $statusCode,
                responseBody: $responseBody,
            );
        }

        if ($statusCode === 422) {
            $errors = [];
            foreach (is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [] as $field => $messages) {
                if (is_string($field) && is_array($messages)) {
                    $errors[$field] = array_values(array_filter($messages, 'is_string'));
                }
            }

            throw new ValidationException(
                message: $message,
                httpStatusCode: $statusCode,
                responseBody: $responseBody,
                errors: $errors,
                errorCode: is_string($error['code'] ?? null) ? $error['code'] : null,
            );
        }

        if ($statusCode === 409 && ($error['code'] ?? null) === 'BATCH_FILES_MISSING') {
            throw new BatchFilesMissingException($message, httpStatusCode: $statusCode, responseBody: $responseBody, errorCode: 'BATCH_FILES_MISSING');
        }

        if ($statusCode === 409 && ($error['code'] ?? null) === 'IDEMPOTENCY_IN_PROGRESS') {
            throw new ConflictException($message, httpStatusCode: $statusCode, responseBody: $responseBody, errorCode: 'IDEMPOTENCY_IN_PROGRESS');
        }

        $serverErrorCode = is_string($error['code'] ?? null) ? $error['code'] : null;
        $exceptionClass = match ($statusCode) {
            401 => AuthenticationException::class,
            403 => AuthorizationException::class,
            409 => ApiException::class,
            413 => PayloadTooLargeException::class,
            415 => UnsupportedMediaTypeException::class,
            400 => ($error['code'] ?? null) === 'BATCH_NOT_READY' ? BatchNotReadyException::class : ApiException::class,
            503 => ($error['code'] ?? null) === 'GENERATION_SETUP_FAILED' ? GenerationSetupFailedException::class : ApiException::class,
            default => ApiException::class,
        };

        throw new $exceptionClass(
            message: $message,
            httpStatusCode: $statusCode,
            responseBody: $responseBody,
            errorCode: $serverErrorCode,
        );
    }
}
