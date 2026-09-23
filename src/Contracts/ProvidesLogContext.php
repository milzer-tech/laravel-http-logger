<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Contracts;

use Saloon\Http\PendingRequest;

/**
 * Implement on a connector and/or request to attach domain context
 * (api, action, client, supplier, ...) to every log entry it produces.
 *
 * Connector context is applied first; request context is merged on top of it.
 */
interface ProvidesLogContext
{
    /**
     * @return array<string, mixed>
     */
    public function logContext(PendingRequest $pendingRequest): array;
}
