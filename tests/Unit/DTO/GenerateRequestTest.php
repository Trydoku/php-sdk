<?php

declare(strict_types=1);

namespace Trydoku\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use Trydoku\DTO\GenerateRequest;

final class GenerateRequestTest extends TestCase
{
    public function testMinimalPayload(): void
    {
        $request = GenerateRequest::create()
            ->withTemplateUuid('e81d77a2-f674-4b53-a8ee-bf350284e311')
            ->withData([
                ['Client_Name' => 'Acme Corp'],
            ]);

        $payload = $request->toArray();

        $this->assertSame('e81d77a2-f674-4b53-a8ee-bf350284e311', $payload['template_uuid']);
        $this->assertCount(1, $payload['data']);
        $this->assertArrayNotHasKey('template_base64', $payload);
        $this->assertArrayNotHasKey('variables', $payload);
        $this->assertArrayNotHasKey('variable_mapping', $payload);
        $this->assertArrayNotHasKey('format', $payload);
    }

    public function testFullPayload(): void
    {
        $request = GenerateRequest::create()
            ->withTemplateUuid('some-uuid')
            ->withData([['Name' => 'Test']])
            ->withVariables(['Name'])
            ->withVariableMapping(['src' => 'Name'])
            ->withFormat('zip');

        $payload = $request->toArray();

        $this->assertSame('some-uuid', $payload['template_uuid']);
        $this->assertSame([['Name' => 'Test']], $payload['data']);
        $this->assertSame(['Name'], $payload['variables']);
        $this->assertSame(['src' => 'Name'], $payload['variable_mapping']);
        $this->assertSame('zip', $payload['format']);
    }

    public function testBase64TemplateRemovesUuid(): void
    {
        $request = GenerateRequest::create()
            ->withTemplateUuid('some-uuid')
            ->withTemplateBase64('base64data')
            ->withData([['Name' => 'Test']]);

        $payload = $request->toArray();

        $this->assertArrayNotHasKey('template_uuid', $payload);
        $this->assertSame('base64data', $payload['template_base64']);
    }

    public function testUuidTemplateRemovesBase64(): void
    {
        $request = GenerateRequest::create()
            ->withTemplateBase64('base64data')
            ->withTemplateUuid('some-uuid')
            ->withData([['Name' => 'Test']]);

        $payload = $request->toArray();

        $this->assertArrayNotHasKey('template_base64', $payload);
        $this->assertSame('some-uuid', $payload['template_uuid']);
    }

    public function testImmutability(): void
    {
        $original = GenerateRequest::create()
            ->withTemplateUuid('uuid-1')
            ->withData([['A' => '1']]);

        $modified = $original->withTemplateUuid('uuid-2');

        $this->assertSame('uuid-1', $original->toArray()['template_uuid']);
        $this->assertSame('uuid-2', $modified->toArray()['template_uuid']);
    }

    public function testIdempotencyKeyIsNotPartOfPayload(): void
    {
        $request = GenerateRequest::create()
            ->withTemplateUuid('some-uuid')
            ->withData([['Name' => 'Test']])
            ->withIdempotencyKey('req-01JABC2DEFG3HIJK4LMNOPQRST');

        $payload = $request->toArray();

        $this->assertSame('req-01JABC2DEFG3HIJK4LMNOPQRST', $request->idempotencyKey());
        $this->assertArrayNotHasKey('idempotency_key', $payload);
        $this->assertArrayNotHasKey('Idempotency-Key', $payload);
    }

    public function testBlankIdempotencyKeyIsOmitted(): void
    {
        $request = GenerateRequest::create()
            ->withTemplateUuid('some-uuid')
            ->withIdempotencyKey('   ');

        $this->assertNull($request->idempotencyKey());
    }

    public function testInvalidIdempotencyKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GenerateRequest::create()->withIdempotencyKey('spaces and symbols!');
    }

    public function testHasTemplateIgnoresWhitespace(): void
    {
        $request = GenerateRequest::create()->withTemplateUuid('   ');

        $this->assertFalse($request->hasTemplate());
        $this->assertArrayNotHasKey('template_uuid', $request->toArray());
    }
}
