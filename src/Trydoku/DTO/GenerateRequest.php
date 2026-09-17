<?php

declare(strict_types=1);

namespace Trydoku\DTO;

/**
 * Immutable builder for a document generation request payload.
 *
 * Calling a template setter replaces any previously selected template source.
 *
 * Usage:
 *   $request = GenerateRequest::create()
 *       ->withTemplateUuid('e81d77a2-...')
 *       ->withData([
 *           ['Client_Name' => 'Acme', 'Amount' => '1250.00'],
 *       ]);
 */
final class GenerateRequest
{
    private ?string $templateUuid = null;
    private ?string $templateBase64 = null;

    /** @var list<array<string|int, mixed>> */
    private array $data = [];

    /** @var string[]|null */
    private ?array $variables = null;

    /** @var array<string, string>|null */
    private ?array $variableMapping = null;

    private ?string $format = null;

    private ?string $idempotencyKey = null;

    private function __construct()
    {
    }

    /**
     * Start an empty request. Chain a template setter and withData() next.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Use a stored template. Clears any previously set Base64 template.
     */
    public function withTemplateUuid(string $uuid): self
    {
        $clone = clone $this;
        $clone->templateUuid = $uuid;
        $clone->templateBase64 = null;

        return $clone;
    }

    /**
     * Use a Base64-encoded `.docx` template. Clears any previously set template UUID.
     */
    public function withTemplateBase64(string $base64): self
    {
        $clone = clone $this;
        $clone->templateBase64 = $base64;
        $clone->templateUuid = null;

        return $clone;
    }

    /**
     * Set the placeholder values, one associative or indexed row per document.
     *
     * @param list<array<string|int, mixed>> $data
     */
    public function withData(array $data): self
    {
        $clone = clone $this;
        $clone->data = $data;

        return $clone;
    }

    /**
     * Set column names for indexed (list) rows.
     *
     * Use this when each `$data` row is a list of values rather than an associative array.
     *
     * @param string[] $variables
     */
    public function withVariables(array $variables): self
    {
        $clone = clone $this;
        $clone->variables = $variables;

        return $clone;
    }

    /**
     * Map source field names in `$data` to template placeholder names.
     *
     * @param array<string, string> $mapping
     */
    public function withVariableMapping(array $mapping): self
    {
        $clone = clone $this;
        $clone->variableMapping = $mapping;

        return $clone;
    }

    /**
     * Set the output format. Currently `"zip"`.
     */
    public function withFormat(string $format): self
    {
        $clone = clone $this;
        $clone->format = $format;

        return $clone;
    }

    /**
     * Set the `Idempotency-Key` header for this request.
     *
     * Must be 1–255 characters of `A-Z`, `a-z`, `0-9`, `.`, `_`, or `-`.
     * Null, empty, or whitespace-only values omit the header.
     *
     * @throws \InvalidArgumentException If the key contains other characters or is too long
     */
    public function withIdempotencyKey(?string $key): self
    {
        $clone = clone $this;
        $clone->idempotencyKey = self::normalizeIdempotencyKey($key);

        return $clone;
    }

    /**
     * Whether a non-empty template UUID or Base64 source is set.
     */
    public function hasTemplate(): bool
    {
        return $this->nonEmpty($this->templateUuid) !== null
            || $this->nonEmpty($this->templateBase64) !== null;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * Build the JSON-serializable request body.
     *
     * The idempotency key is sent as a header, not in this payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        $templateUuid = $this->nonEmpty($this->templateUuid);
        if ($templateUuid !== null) {
            $payload['template_uuid'] = $templateUuid;
        }

        $templateBase64 = $this->nonEmpty($this->templateBase64);
        if ($templateBase64 !== null) {
            $payload['template_base64'] = $templateBase64;
        }

        $payload['data'] = $this->data;

        if ($this->variables !== null) {
            $payload['variables'] = $this->variables;
        }

        if ($this->variableMapping !== null) {
            $payload['variable_mapping'] = $this->variableMapping;
        }

        if ($this->format !== null) {
            $payload['format'] = $this->format;
        }

        return $payload;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function normalizeIdempotencyKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $trimmed = trim($key);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._-]{1,255}$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException(
                'Idempotency-Key must be 1–255 characters of A-Z, a-z, 0-9, ".", "_" or "-".'
            );
        }

        return $trimmed;
    }

    private function nonEmpty(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
