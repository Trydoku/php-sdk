<?php

declare(strict_types=1);

namespace Trydoku\Resource;

use Trydoku\DTO\Batch;
use Trydoku\DTO\GenerateRequest;
use Trydoku\HttpClient\AuthenticatedClient;

/**
 * Operations for starting document generation batches.
 *
 * @see https://www.trydoku.com/docs/api
 */
final class Documents
{
    public function __construct(
        private readonly AuthenticatedClient $client,
    ) {
    }

    /**
     * Start a new document generation batch.
     *
     * Provide exactly one of `$templateUuid` or `$templateBase64`.
     *
     * @param string|null $templateUuid UUID of a stored template you own
     * @param string|null $templateBase64 Base64-encoded `.docx` file (non-macro, max 10 MiB decoded)
     * @param list<array<string|int, mixed>> $data Rows of placeholder values (1–500)
     * @param string[]|null $variables Column names when `$data` rows are indexed lists rather than maps
     * @param array<string, string>|null $variableMapping Maps source field names to template placeholders
     * @param string|null $format Output format. Currently `"zip"`.
     * @param string|null $idempotencyKey Optional `Idempotency-Key` header (1–255 of `A-Z a-z 0-9 . _ -`)
     *
     * @throws \InvalidArgumentException If no template source, both sources, invalid idempotency key, or row count outside 1–500 is given
     * @throws \Trydoku\Exception\TrydokuException If the API rejects the request
     */
    public function generate(
        ?string $templateUuid = null,
        ?string $templateBase64 = null,
        array $data = [],
        ?array $variables = null,
        ?array $variableMapping = null,
        ?string $format = null,
        ?string $idempotencyKey = null,
    ): Batch {
        $templateUuid = self::nonEmpty($templateUuid);
        $templateBase64 = self::nonEmpty($templateBase64);

        if ($templateUuid !== null && $templateBase64 !== null) {
            throw new \InvalidArgumentException('Provide either a template UUID or a Base64 template, not both.');
        }

        $request = GenerateRequest::create()->withData($data);

        if ($templateUuid !== null) {
            $request = $request->withTemplateUuid($templateUuid);
        }

        if ($templateBase64 !== null) {
            $request = $request->withTemplateBase64($templateBase64);
        }

        if ($variables !== null) {
            $request = $request->withVariables($variables);
        }

        if ($variableMapping !== null) {
            $request = $request->withVariableMapping($variableMapping);
        }

        if ($format !== null) {
            $request = $request->withFormat($format);
        }

        if ($idempotencyKey !== null) {
            $request = $request->withIdempotencyKey($idempotencyKey);
        }

        return $this->generateFromRequest($request);
    }

    /**
     * Start a new document generation batch from a GenerateRequest object.
     *
     * @throws \InvalidArgumentException If the request has no template source or row count outside 1–500
     * @throws \Trydoku\Exception\TrydokuException If the API rejects the request
     */
    public function generateFromRequest(GenerateRequest $request): Batch
    {
        if (!$request->hasTemplate()) {
            throw new \InvalidArgumentException('Provide either a template UUID or a Base64 template.');
        }

        $payload = $request->toArray();
        $rowCount = count($payload['data']);
        if ($rowCount < 1 || $rowCount > 500) {
            throw new \InvalidArgumentException('Generation requests must contain between 1 and 500 data rows.');
        }

        $headers = [];
        $idempotencyKey = $request->idempotencyKey();
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $context = $this->client->requestWithContext('POST', '/generate', $payload, $headers);

        return Batch::fromApiResponse($context->data, $context);
    }

    private static function nonEmpty(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
