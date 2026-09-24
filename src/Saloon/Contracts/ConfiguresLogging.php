<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Saloon\Contracts;

use Milzer\HttpLogger\Core\LoggingOptions;
use Saloon\Http\PendingRequest;

/**
 * Implement on a connector and/or request to tweak logging for it: change the
 * messages, redact extra keys, skip bodies, send to another logger or disable it.
 *
 * The connector is asked first, then the request receives the connector's result.
 */
interface ConfiguresLogging
{
    public function configureLogging(LoggingOptions $options, PendingRequest $pendingRequest): LoggingOptions;
}
