<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * Thrown for unmapped API errors, invalid JSON responses, and exhausted polling.
 */
class ApiException extends TrydokuException
{
}
