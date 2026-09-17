<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * HTTP 415 — `Content-Encoding` must be omitted or exactly `identity`.
 */
class UnsupportedMediaTypeException extends TrydokuException
{
}
