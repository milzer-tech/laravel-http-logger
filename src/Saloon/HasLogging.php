<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Saloon;

use Milzer\HttpLogger\Core\HttpLogger;
use Saloon\Http\PendingRequest;

/**
 * Saloon plugin: add to a connector (logs every request it sends) or to a single request.
 *
 * Using it on both a connector and one of its requests is safe; the exchange is logged once.
 */
trait HasLogging
{
    public function bootHasLogging(PendingRequest $pendingRequest): void
    {
        SaloonLogging::register(HttpLogger::resolve(), $pendingRequest);
    }
}
