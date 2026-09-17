# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial release of the TRYDOKU PHP SDK
- `Client` class as the main entry point
- `Documents` resource for batch document generation (`POST /v1/generate`)
- `Batches` resource for status polling and ZIP download
- `Batch`, `BatchItem`, `BatchLinks` DTO objects
- `GenerateRequest` builder for constructing generation payloads
- PSR-18 HTTP client support with `php-http/discovery` auto-detection
- `AuthenticatedClient` HTTP adapter for Bearer token authentication
- Typed exception hierarchy mapping all API error codes
- `waitForCompletion()` polling helper with configurable attempts and interval
- `Idempotency-Key` support on generate requests
- `ConflictException`, `UnsupportedMediaTypeException`, and `IdempotencyKeyConflictException`
- `Batch::isSetupPending()` for HTTP 202 `GENERATION_SETUP_PENDING`
- GitHub Actions CI, `SECURITY.md`, and `.gitattributes` export rules

### Fixed
- Continue polling after individual row failures until the batch finishes or reports a terminal failure
- Validate polling arguments and encode batch IDs as URL path segments
- Reject conflicting template sources in `Documents::generate()`
- Reject generate requests that include neither a template UUID nor a Base64 template
- Continue polling while generation setup is still pending
- Map incomplete batch payloads to `ApiException` instead of PHP errors
- Preserve API exception types for malformed error fields and retain invalid JSON response context
- Correct PHPDoc, dependency claims, and exception handling documentation

### Changed
- Remove the unused `Config::timeout` option; configure timeouts on the supplied HTTP client
- Add a shared code style configuration and Composer test, lint, and format commands
- Rewrite the README for open-source readers and clarify PHPDoc across the public API
