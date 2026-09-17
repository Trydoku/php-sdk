<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * HTTP 409 — The Idempotency-Key is already in progress, or its reservation
 * has no stored success response yet. Retry with the same key; do not mint a new one.
 */
class ConflictException extends TrydokuException
{
}
