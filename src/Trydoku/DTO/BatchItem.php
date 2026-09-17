<?php

declare(strict_types=1);

namespace Trydoku\DTO;

/**
 * A single document row within a batch.
 */
final class BatchItem
{
    public function __construct(
        public readonly int $rowIndex,
        public readonly string $status,
        public readonly ?string $downloadUrl = null,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException If a required field is missing or the wrong type
     */
    public static function fromArray(array $data): self
    {
        $rowIndex = $data['row_index'] ?? null;
        if (!is_numeric($rowIndex) || is_bool($rowIndex)) {
            throw new \InvalidArgumentException('The batch item is missing a valid "row_index" field.');
        }

        $status = $data['status'] ?? null;
        if (!is_string($status) || $status === '') {
            throw new \InvalidArgumentException('The batch item is missing a valid "status" field.');
        }

        $downloadUrl = $data['download_url'] ?? null;
        if ($downloadUrl !== null && !is_string($downloadUrl)) {
            throw new \InvalidArgumentException('The batch item has an invalid "download_url" field.');
        }

        $error = $data['error'] ?? null;
        if ($error !== null && !is_string($error)) {
            throw new \InvalidArgumentException('The batch item has an invalid "error" field.');
        }

        return new self(
            rowIndex: (int) $rowIndex,
            status: $status,
            downloadUrl: $downloadUrl,
            error: $error,
        );
    }

    /**
     * Whether this row finished successfully.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Whether this row failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
