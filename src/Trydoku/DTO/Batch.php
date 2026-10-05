<?php

declare(strict_types=1);

namespace Trydoku\DTO;

use Trydoku\Exception\ApiException;
use Trydoku\HttpClient\ResponseContext;

/**
 * A document generation batch returned by the API.
 */
final class Batch
{
    /**
     * @param BatchItem[] $items
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $setupStatus,
        public readonly int $totalItems,
        public readonly int $processedItems,
        public readonly int $failedItems,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly BatchLinks $links,
        public readonly array $items = [],
        public readonly ?string $code = null,
    ) {
    }

    /**
     * Map a full API envelope (`data`, optional `code`) to a Batch.
     *
     * @param array<string, mixed> $response
     *
     * @throws ApiException If the envelope is missing or the batch payload is invalid
     */
    public static function fromApiResponse(array $response, ?ResponseContext $context = null): self
    {
        try {
            $data = $response['data'] ?? null;
            if (!is_array($data)) {
                throw new \InvalidArgumentException('The API response is missing a batch object.');
            }

            $code = $response['code'] ?? null;

            if ($context?->itemsIsJsonObject) {
                throw new \InvalidArgumentException('The batch payload has an invalid "items" list.');
            }

            return self::fromArray($data, is_string($code) ? $code : null);
        } catch (\InvalidArgumentException $exception) {
            $encoded = json_encode($response);

            throw new ApiException(
                message: $exception->getMessage(),
                previous: $exception,
                httpStatusCode: $context?->httpStatusCode,
                responseBody: $context?->responseBody ?? (is_string($encoded) ? $encoded : null),
                responseBodyTruncated: $context?->responseBodyTruncated ?? false,
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException If a required field is missing or the wrong type. Timestamps must use
     *                                   YYYY-MM-DDTHH:MM:SS with optional 1–6 fractional digits and a timezone.
     */
    public static function fromArray(array $data, ?string $code = null): self
    {
        $items = [];
        if (array_key_exists('items', $data)) {
            if (!is_array($data['items']) || !array_is_list($data['items'])) {
                throw new \InvalidArgumentException('The batch payload has an invalid "items" list.');
            }
            foreach ($data['items'] as $index => $item) {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException("The batch payload has an invalid items[{$index}] entry.");
                }

                $items[] = BatchItem::fromArray($item);
            }
        }

        $links = $data['links'] ?? null;
        if (!is_array($links)) {
            throw new \InvalidArgumentException('The batch payload is missing a valid "links" field.');
        }

        return new self(
            id: self::requireString($data, 'id'),
            status: self::requireString($data, 'status'),
            setupStatus: self::optionalString($data, 'setup_status'),
            totalItems: self::requireInt($data, 'total_items'),
            processedItems: self::requireInt($data, 'processed_items'),
            failedItems: self::requireInt($data, 'failed_items'),
            createdAt: self::optionalDateTime($data['created_at'] ?? null, 'created_at'),
            updatedAt: self::optionalDateTime($data['updated_at'] ?? null, 'updated_at'),
            links: BatchLinks::fromArray($links),
            items: $items,
            code: $code,
        );
    }

    /**
     * Whether the batch has finished processing.
     *
     * A completed batch may still contain failed rows; check hasFailed() for that.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Whether the batch is still being processed.
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Whether generation setup is still pending (HTTP 202 `GENERATION_SETUP_PENDING`).
     *
     * Keep polling with waitForCompletion(); do not download a ZIP yet.
     */
    public function isSetupPending(): bool
    {
        return $this->code === 'GENERATION_SETUP_PENDING' || $this->setupStatus === 'pending';
    }

    /**
     * Whether any row in the batch failed.
     */
    public function hasFailed(): bool
    {
        return $this->failedItems > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("The batch payload is missing a valid \"{$key}\" field.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if (!is_string($value)) {
            throw new \InvalidArgumentException("The batch payload has an invalid \"{$key}\" field.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_numeric($value) || is_bool($value)) {
            throw new \InvalidArgumentException("The batch payload is missing a valid \"{$key}\" field.");
        }

        return (int) $value;
    }

    private static function optionalDateTime(mixed $value, string $key): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException("The batch payload has an invalid \"{$key}\" field.");
        }

        $pattern = '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D';
        if (preg_match($pattern, $value, $matches) !== 1
            || ((int) substr($matches[4], 1, 2) === 14 && substr($matches[4], 4, 2) !== '00')) {
            throw new \InvalidArgumentException("The batch payload has an invalid \"{$key}\" field.");
        }

        $fraction = str_pad($matches[3] ?? '', 6, '0');
        $zone = $matches[4] === 'Z' ? '+00:00' : $matches[4];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.uP', $matches[1] . 'T' . $matches[2] . '.' . $fraction . $zone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException("The batch payload has an invalid \"{$key}\" field.");
        }

        return $date;
    }
}
