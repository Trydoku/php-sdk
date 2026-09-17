<?php

declare(strict_types=1);

namespace Trydoku\Exception;

/**
 * HTTP 403 — The token is valid but does not have permission for this action.
 */
class AuthorizationException extends TrydokuException
{
}
