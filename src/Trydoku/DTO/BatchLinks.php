<?php

declare(strict_types=1);

namespace Trydoku\DTO;

/**
 * Related resource URLs returned with every batch response.
 */
final class BatchLinks
{
    public function __construct(
        public readonly string $self,
        public readonly ?string $zip = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException If `self` is missing or not a string
     */
    public static function fromArray(array $data): self
    {
        $self = $data['self'] ?? null;
        if (!is_string($self) || $self === '') {
            throw new \InvalidArgumentException('The batch payload is missing a valid "links.self" field.');
        }

        $zip = $data['zip'] ?? null;
        if ($zip !== null && !is_string($zip)) {
            throw new \InvalidArgumentException('The batch payload has an invalid "links.zip" field.');
        }

        return new self(
            self: $self,
            zip: $zip !== '' ? $zip : null,
        );
    }
}
