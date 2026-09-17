<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * HTTP 503 with code `GENERATION_SETUP_FAILED` — Generation could not start; credits were refunded.
 */
class GenerationSetupFailedException extends TrydokuException
{
}
