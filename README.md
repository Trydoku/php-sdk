# TRYDOKU PHP SDK

Official PHP client for the [TRYDOKU](https://www.trydoku.com) document generation API.

Fill a Word template with structured data and download the results as `.docx` or PDF files — invoices, contracts, reports, and similar documents — from PHP.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-8892BF.svg)](https://php.net)

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Get an API token](#get-an-api-token)
- [Quick start](#quick-start)
- [Usage](#usage)
  - [Create a client](#create-a-client)
  - [Generate from a stored template](#generate-from-a-stored-template)
  - [Generate from a local `.docx` file](#generate-from-a-local-docx-file)
  - [Map source fields to placeholders](#map-source-fields-to-placeholders)
  - [Indexed rows](#indexed-rows)
  - [Request builder](#request-builder)
  - [Idempotency](#idempotency)
  - [Check batch status](#check-batch-status)
  - [Wait for completion](#wait-for-completion)
  - [Download a ZIP](#download-a-zip)
- [Error handling](#error-handling)
- [Development](#development)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)
- [Support](#support)

## Requirements

- PHP 8.2 or newer
- A [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client (for example Guzzle or Symfony HttpClient)
- A [PSR-17](https://www.php-fig.org/psr/psr-17/) HTTP factory (for example `guzzlehttp/psr7` or `nyholm/psr7`)

The SDK talks to HTTP through those interfaces. It does not bundle an HTTP client, so you pick the one that already fits your app.

## Installation

```bash
composer require trydoku/php-sdk
```

Then install an HTTP client if your project does not already have one.

**Laravel or standalone PHP:**

```bash
composer require guzzlehttp/guzzle guzzlehttp/psr7
```

**Symfony:**

```bash
composer require symfony/http-client nyholm/psr7
```

If Composer asks to allow the `php-http/discovery` plugin, accept it. The SDK uses that plugin to find the HTTP client you installed.

## Get an API token

1. Sign in at [trydoku.com](https://www.trydoku.com).
2. Open **Account Settings → API Keys**.
3. Create a personal access token.

Keep the token out of git, logs, and frontend code. Changing your account password revokes every existing token.

## Quick start

```php
<?php

require 'vendor/autoload.php';

use Trydoku\Client;

$client = new Client(getenv('TRYDOKU_API_TOKEN'));

// 1. Start a batch from a template stored in TRYDOKU
$batch = $client->documents()->generate(
    templateUuid: 'e81d77a2-f674-4b53-a8ee-bf350284e311',
    data: [
        [
            'Client_Name' => 'Acme Corporation',
            'Invoice_Number' => 'INV-2026-001',
            'Amount_Due' => '1250.00',
        ],
        [
            'Client_Name' => 'Globex Industries',
            'Invoice_Number' => 'INV-2026-002',
            'Amount_Due' => '3400.00',
        ],
    ],
);

echo "Batch {$batch->id} is {$batch->status}\n";

// 2. Generation is asynchronous — poll until the batch finishes
$batch = $client->batches()->waitForCompletion($batch->id);

if (!$batch->isCompleted() || $batch->hasFailed()) {
    throw new RuntimeException(
        "Generation finished with status {$batch->status} ({$batch->failedItems} failed rows)."
    );
}

// 3. Download every completed document as a single ZIP
file_put_contents('documents.zip', $client->batches()->downloadZip($batch->id));

echo "Saved documents.zip ({$batch->processedItems}/{$batch->totalItems} rows)\n";
```

Each item in `data` becomes one document. Placeholder names in the template (`Client_Name`, and so on) must match the keys you send, unless you add a [variable mapping](#map-source-fields-to-placeholders).

## Usage

### Create a client

```php
use Trydoku\Client;
use Trydoku\Config;

// Token only — the default production URL is used
$client = new Client('YOUR_API_TOKEN');

// Custom base URL (staging or a reverse proxy)
$client = new Client(new Config(
    apiToken: 'YOUR_API_TOKEN',
    baseUrl: 'https://www.trydoku.com/api/v1',
));

// Your own PSR-18 client, with timeouts configured there
$http = new GuzzleHttp\Client(['timeout' => 60]);
$client = new Client('YOUR_API_TOKEN', httpClient: $http);
```

Timeouts, retries, and proxies belong on the HTTP client you pass in. The SDK does not take a timeout option of its own.

### Generate from a stored template

```php
$batch = $client->documents()->generate(
    templateUuid: 'e81d77a2-f674-4b53-a8ee-bf350284e311',
    data: [
        ['Client_Name' => 'Acme Corp', 'Amount' => '1250.00'],
    ],
);
```

Pass either `templateUuid` or `templateBase64`, never both, and never neither. A batch accepts 1–500 rows. The SDK throws `InvalidArgumentException` before it sends the request if those rules are broken.

### Generate from a local `.docx` file

```php
$batch = $client->documents()->generate(
    templateBase64: base64_encode(file_get_contents('template.docx')),
    data: [
        ['Client_Name' => 'Acme Corp', 'Amount' => '1250.00'],
    ],
);
```

The file must be a non-macro Word document. Decoded size is limited to 10 MiB.

### Map source fields to placeholders

Use this when your data keys do not match the placeholder names in the template:

```php
$batch = $client->documents()->generate(
    templateUuid: 'e81d77a2-f674-4b53-a8ee-bf350284e311',
    data: [
        ['client' => 'Acme Corp', 'total' => '1250.00'],
    ],
    variableMapping: [
        'client' => 'Client_Name',
        'total' => 'Amount_Due',
    ],
);
```

### Indexed rows

If each row is a list of values instead of a map, send the column names separately:

```php
$batch = $client->documents()->generate(
    templateUuid: 'e81d77a2-f674-4b53-a8ee-bf350284e311',
    data: [
        ['Acme Corp', 'INV-2026-001', '1250.00'],
        ['Globex Industries', 'INV-2026-002', '3400.00'],
    ],
    variables: ['Client_Name', 'Invoice_Number', 'Amount_Due'],
);
```

### Request builder

`GenerateRequest` is immutable. Each `with*()` method returns a new instance:

```php
use Trydoku\DTO\GenerateRequest;

$request = GenerateRequest::create()
    ->withTemplateUuid('e81d77a2-f674-4b53-a8ee-bf350284e311')
    ->withData([
        ['Client_Name' => 'Acme Corp'],
    ])
    ->withFormat('zip');

$batch = $client->documents()->generateFromRequest($request);
```

Setting `withTemplateUuid()` clears a previously set Base64 template, and the other way around.

### Idempotency

Pass a stable key so a retried `POST /v1/generate` does not create a second batch. Successful 2xx responses are replayed for 24 hours for the same user, key, and body.

```php
$batch = $client->documents()->generate(
    templateUuid: 'e81d77a2-f674-4b53-a8ee-bf350284e311',
    data: [
        ['Client_Name' => 'Acme Corp'],
    ],
    idempotencyKey: 'invoice-run-2026-09-17',
);
```

Or on the builder:

```php
$request = GenerateRequest::create()
    ->withTemplateUuid('e81d77a2-f674-4b53-a8ee-bf350284e311')
    ->withData([['Client_Name' => 'Acme Corp']])
    ->withIdempotencyKey('invoice-run-2026-09-17');

$batch = $client->documents()->generateFromRequest($request);
```

The key must be 1–255 characters of `A–Z`, `a–z`, `0–9`, `.`, `_`, or `-`. Empty or whitespace-only values are omitted.

If a request with that key is still running, the API returns HTTP 409 (`ConflictException`). Retry the **same** key — a new key after 409 can duplicate work. If the key is reused with a different body, the API returns HTTP 422 (`IdempotencyKeyConflictException`).

### Check batch status

```php
$batch = $client->batches()->get('a91b22e1-a714-4113-a83d-bc350284e300');

echo $batch->id;             // "a91b22e1-..."
echo $batch->status;         // "processing" | "completed" | "failed"
echo $batch->totalItems;     // 2
echo $batch->processedItems; // 2
echo $batch->failedItems;    // 0
echo $batch->links->zip;     // ZIP URL once the batch has completed, otherwise null

$batch->isCompleted();    // true when status === "completed"
$batch->isProcessing();   // true when status === "processing"
$batch->isSetupPending(); // true for HTTP 202 GENERATION_SETUP_PENDING
$batch->hasFailed();      // true when failedItems > 0

foreach ($batch->items as $item) {
    echo "Row {$item->rowIndex}: {$item->status}";

    if ($item->downloadUrl) {
        echo " → {$item->downloadUrl}";
    }

    if ($item->error) {
        echo " [{$item->error}]";
    }

    echo "\n";
}
```

`isCompleted()` means the batch finished, not that every row succeeded. Always check `hasFailed()` before you treat the ZIP as complete. If `isSetupPending()` is true, keep polling — setup has not finished yet.

### Wait for completion

```php
$batch = $client->batches()->waitForCompletion(
    batchId: $batch->id,
    maxAttempts: 30,   // default 30
    intervalMs: 2000,  // default 2000 ms
);

if ($batch->isCompleted() && !$batch->hasFailed()) {
    $zip = $client->batches()->downloadZip($batch->id);
    file_put_contents('result.zip', $zip);
}
```

Polling stops when the batch completes, when `status` or `setupStatus` is `"failed"`, or when `$maxAttempts` is used up. A **202 setup-pending** batch keeps polling. A failed **row** does not stop polling while other rows are still running.

`$maxAttempts` must be at least 1. `$intervalMs` must be zero or a positive number that fits in microseconds.

If the batch is still processing after the last attempt, `waitForCompletion()` throws `ApiException`.

### Download a ZIP

```php
$zipContent = $client->batches()->downloadZip('a91b22e1-a714-4113-a83d-bc350284e300');
file_put_contents('result.zip', $zipContent);
```

Downloading before the batch has completed throws `BatchNotReadyException`.

## Error handling

API errors throw a subclass of `TrydokuException`. Catch the specific type when you need its extra fields; catch `TrydokuException` for everything else.

Transport errors (`Psr\Http\Client\ClientExceptionInterface`), bad local arguments (`InvalidArgumentException`), and JSON encoding failures (`JsonException`) are **not** wrapped. They keep their original types.

```php
use Trydoku\Exception\AuthenticationException;
use Trydoku\Exception\InsufficientCreditsException;
use Trydoku\Exception\TrydokuException;
use Trydoku\Exception\ValidationException;

try {
    $batch = $client->documents()->generate(
        templateUuid: 'e81d77a2-f674-4b53-a8ee-bf350284e311',
        data: [['Name' => 'Test']],
    );
} catch (AuthenticationException $e) {
    // 401 — missing or invalid token
    echo "Auth error: {$e->getMessage()}\n";
} catch (InsufficientCreditsException $e) {
    // 402 — not enough credits for this batch
    echo "Credits: {$e->creditsAvailable} available, {$e->creditsRequired} required\n";
} catch (ValidationException $e) {
    // 422 — payload or template schema mismatch
    foreach ($e->getErrors() as $field => $messages) {
        echo "{$field}: " . implode(', ', $messages) . "\n";
    }
} catch (TrydokuException $e) {
    echo "Error [{$e->httpStatusCode}]: {$e->getMessage()}\n";
    echo "Response: {$e->responseBody}\n";
}
```

| Exception | HTTP | When |
|-----------|------|------|
| `AuthenticationException` | 401 | Token missing or invalid |
| `InsufficientCreditsException` | 402 | Not enough credits for the request |
| `AuthorizationException` | 403 | Token lacks permission |
| `BatchNotReadyException` | 400 | ZIP requested before the batch completed |
| `ConflictException` | 409 | Idempotency-Key is already in progress — retry the same key |
| `PayloadTooLargeException` | 413 | Request body larger than 20 MiB |
| `UnsupportedMediaTypeException` | 415 | `Content-Encoding` is set and is not `identity` |
| `ValidationException` | 422 | Invalid payload or template schema mismatch |
| `IdempotencyKeyConflictException` | 422 | Idempotency-Key reused with a different body |
| `GenerationSetupFailedException` | 503 | Generation could not start; credits were refunded |
| `ApiException` | * | Any other API error, invalid JSON, incomplete payload, or polling timeout |

## API reference

- [API overview](https://www.trydoku.com/docs/api) — authentication, payloads, and examples
- [Interactive reference](https://www.trydoku.com/docs/api/reference) — OpenAPI explorer

| Method | Endpoint | SDK method |
|--------|----------|------------|
| `POST` | `/v1/generate` | `$client->documents()->generate(...)` |
| `GET` | `/v1/batches/{id}` | `$client->batches()->get($id)` |
| `GET` | `/v1/batches/{id}/zip` | `$client->batches()->downloadZip($id)` |

## Development

Pull requests run unit tests and lint on PHP 8.2, 8.3, and 8.4 via GitHub Actions.

```bash
composer install

# Unit tests
composer test

# Integration tests against the live API
TRYDOKU_API_TOKEN=xxx TRYDOKU_TEMPLATE_UUID=yyy ./vendor/bin/phpunit --testsuite integration

# Mutation tests
./vendor/bin/infection --threads=4

# Code style
composer lint     # check
composer format   # rewrite
```

## Contributing

Bug reports and pull requests are welcome. Please:

1. Open an issue first for larger changes.
2. Keep the public API backwards-compatible unless the change is a major version.
3. Add or update unit tests for the behaviour you change.
4. Run `composer test` and `composer lint` before you open a pull request.

## Security

Please report vulnerabilities privately. See [SECURITY.md](SECURITY.md).

## License

Released under the [MIT License](LICENSE).

## Support

- Documentation: [trydoku.com/docs](https://www.trydoku.com/docs)
- API reference: [trydoku.com/docs/api/reference](https://www.trydoku.com/docs/api/reference)
- Email: [support@trydoku.com](mailto:support@trydoku.com)
