<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * HTTP 422 with code `IDEMPOTENCY_KEY_CONFLICT` — The Idempotency-Key was
 * reused with a different request body.
 */
class IdempotencyKeyConflictException extends TrydokuException
{
}
