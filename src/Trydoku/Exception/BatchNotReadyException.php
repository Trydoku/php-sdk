<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * HTTP 400 with code `BATCH_NOT_READY` — The ZIP is not ready because the batch is still processing.
 */
class BatchNotReadyException extends TrydokuException
{
}
