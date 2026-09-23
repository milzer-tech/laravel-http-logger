<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Exceptions;

use LogicException;
use Milzer\SaloonLogger\SaloonLogger;

final class MissingLoggerException extends LogicException
{
    public static function make(): self
    {
        return new self(sprintf(
            'No default Saloon logger has been configured. Call %s::setDefault() during bootstrap, '
            .'or install the package in a Laravel application so the service provider does it for you.',
            SaloonLogger::class,
        ));
    }
}
