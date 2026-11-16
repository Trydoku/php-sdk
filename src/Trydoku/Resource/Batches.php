<?php

declare(strict_types=1);

namespace Trydoku\Resource;

use Trydoku\DTO\Batch;
use Trydoku\Exception\ApiException;
use Trydoku\Exception\TrydokuException;
use Trydoku\HttpClient\AuthenticatedClient;

/**
 * Operations for inspecting batches and downloading ZIP archives.
 *
 * @see https://www.trydoku.com/docs/api
 */
final class Batches
{
    public function __construct(
        private readonly AuthenticatedClient $client,
    ) {
    }

    /**
     * Fetch the current status, counters, links, and items for a batch.
     *
     * @throws TrydokuException If the API returns an error
     * @throws \InvalidArgumentException If the batch ID is empty or a dot path segment
     */
    public function get(string $batchId): Batch
    {
        self::validateBatchId($batchId);
        $batchId = rawurlencode($batchId);
        $context = $this->client->requestWithContext('GET', "/batches/{$batchId}");

        return Batch::fromApiResponse($context->data, $context);
    }

    /**
     * Download all completed documents as a ZIP archive.
     *
     * Returns the raw ZIP bytes as a string, so the complete archive is held in memory.
     * Use downloadZipTo() for large archives. Call this only after the batch has
     * completed; otherwise the API throws BatchNotReadyException.
     *
     * @throws \Trydoku\Exception\BatchNotReadyException If the batch is still processing
     * @throws TrydokuException If the API returns another error
     * @throws \InvalidArgumentException If the batch ID is empty or a dot path segment
     */
    public function downloadZip(string $batchId): string
    {
        self::validateBatchId($batchId);
        $batchId = rawurlencode($batchId);
        $response = $this->client->requestRawResponse('GET', "/batches/{$batchId}/zip");
        self::assertZipStatus($response->getStatusCode());
        $zip = (string) $response->getBody();
        self::assertZipHeader(substr($zip, 0, 4), $response->getStatusCode());

        return $zip;
    }

    /**
     * Stream the completed batch ZIP to a writable PHP stream resource.
     *
     * This avoids holding the full archive in a PHP string. The destination is
     * left open and may contain a partial archive if a transport or write error occurs.
     *
     * @param resource $destination Writable stream, such as a file handle or `php://output`
     * @return int Number of bytes written
     *
     * @throws \InvalidArgumentException If the batch ID or destination is invalid
     * @throws ApiException If the successful API response is not a ZIP archive
     * @throws TrydokuException If the API returns an error
     */
    public function downloadZipTo(string $batchId, mixed $destination): int
    {
        self::validateBatchId($batchId);
        self::validateWritableStream($destination);

        $batchId = rawurlencode($batchId);
        $response = $this->client->requestRawResponse('GET', "/batches/{$batchId}/zip");
        self::assertZipStatus($response->getStatusCode());
        $body = $response->getBody();
        $header = self::readZipHeader($body);
        self::assertZipHeader($header, $response->getStatusCode());

        $bytesWritten = self::writeAll($destination, $header);
        while (true) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                if ($body->eof()) {
                    break;
                }

                throw new \RuntimeException('The API response stream stopped before the ZIP download completed.');
            }

            $bytesWritten += self::writeAll($destination, $chunk);
        }

        return $bytesWritten;
    }

    private static function validateBatchId(string $batchId): void
    {
        if ($batchId === '' || $batchId === '.' || $batchId === '..') {
            throw new \InvalidArgumentException('Batch ID must not be empty or a dot path segment.');
        }
    }

    private static function validateWritableStream(mixed $destination): void
    {
        if (!is_resource($destination) || get_resource_type($destination) !== 'stream') {
            throw new \InvalidArgumentException('ZIP destination must be a writable stream resource.');
        }

        $mode = stream_get_meta_data($destination)['mode'];
        if (strpbrk($mode, 'waxc+') === false) {
            throw new \InvalidArgumentException('ZIP destination stream is not writable.');
        }
    }

    private static function readZipHeader(\Psr\Http\Message\StreamInterface $body): string
    {
        $header = '';
        while (strlen($header) < 4 && !$body->eof()) {
            $chunk = $body->read(4 - strlen($header));
            if ($chunk === '') {
                break;
            }
            $header .= $chunk;
        }

        return $header;
    }

    private static function assertZipStatus(int $statusCode): void
    {
        if ($statusCode !== 200) {
            throw new ApiException(
                message: 'The API response is not a complete ZIP archive.',
                httpStatusCode: $statusCode,
            );
        }
    }

    private static function assertZipHeader(string $header, int $statusCode): void
    {
        if (strlen($header) === 4 && in_array($header, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            return;
        }

        throw new ApiException(
            message: 'The API response is not a complete ZIP archive.',
            httpStatusCode: $statusCode,
        );
    }

    /** @param resource $destination */
    private static function writeAll(mixed $destination, string $contents): int
    {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($destination, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Could not write the complete ZIP download to its destination.');
            }
            $offset += $written;
        }

        return $offset;
    }

    /**
     * Poll the batch until it completes or fails, or until `$maxAttempts` is reached.
     *
     * HTTP 202 setup-pending batches keep polling. Failures on individual rows
     * do not stop polling while other rows are still processing.
     * Inspect `isCompleted()` and `hasFailed()` on the returned batch before downloading.
     *
     * @param string $batchId Batch ID returned by generate()
     * @param int $maxAttempts Maximum number of status checks (default: 30)
     * @param int $intervalMs Milliseconds to wait between checks (default: 2000)
     *
     * @throws \InvalidArgumentException If `$maxAttempts` is less than 1 or `$intervalMs` is out of range
     * @throws ApiException If the batch is still processing after `$maxAttempts` checks
     * @throws TrydokuException If the API returns an error while polling
     */
    public function waitForCompletion(
        string $batchId,
        int $maxAttempts = 30,
        int $intervalMs = 2000,
    ): Batch {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Maximum polling attempts must be at least 1.');
        }

        if ($intervalMs < 0 || $intervalMs > intdiv(PHP_INT_MAX, 1000)) {
            throw new \InvalidArgumentException('Polling interval must be non-negative and fit in microseconds.');
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $batch = $this->get($batchId);

            $isTerminal = !$batch->isSetupPending()
                && ($batch->isCompleted() || $batch->status === 'failed' || $batch->setupStatus === 'failed');

            if ($isTerminal) {
                return $batch;
            }

            if ($attempt < $maxAttempts) {
                usleep($intervalMs * 1000);
            }
        }

        throw new ApiException(
            message: "Batch {$batchId} did not complete within {$maxAttempts} polling attempts.",
            httpStatusCode: null,
            responseBody: null,
        );
    }
}
