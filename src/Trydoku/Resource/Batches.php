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
     * Returns the raw ZIP bytes. Call this only after the batch has completed;
     * otherwise the API throws BatchNotReadyException.
     *
     * @throws \Trydoku\Exception\BatchNotReadyException If the batch is still processing
     * @throws TrydokuException If the API returns another error
     */
    public function downloadZip(string $batchId): string
    {
        self::validateBatchId($batchId);
        $batchId = rawurlencode($batchId);

        return $this->client->requestRaw('GET', "/batches/{$batchId}/zip");
    }

    private static function validateBatchId(string $batchId): void
    {
        if ($batchId === '' || $batchId === '.' || $batchId === '..') {
            throw new \InvalidArgumentException('Batch ID must not be empty or a dot path segment.');
        }
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
