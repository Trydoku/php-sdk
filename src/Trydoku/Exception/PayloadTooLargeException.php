<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * HTTP 413 — The request body exceeds the 20 MiB limit.
 */
class PayloadTooLargeException extends TrydokuException
{
}
