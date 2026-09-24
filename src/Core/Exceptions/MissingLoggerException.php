<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Exceptions;

use LogicException;
use Milzer\HttpLogger\Core\HttpLogger;

final class MissingLoggerException extends LogicException
{
    public static function make(): self
    {
        return new self(sprintf(
            'No default HTTP logger has been configured. Make sure the service provider is registered '
            .'(it is auto-discovered) or call %s::setDefault().',
            HttpLogger::class,
        ));
    }
}
