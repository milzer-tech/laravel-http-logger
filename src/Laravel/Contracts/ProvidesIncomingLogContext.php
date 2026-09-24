<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Laravel\Contracts;

use Illuminate\Http\Request;

/**
 * Adds your own properties (client, api, action, ...) to incoming request/response entries.
 * Register the implementing class in config/http-logger.php under incoming.context.
 */
interface ProvidesIncomingLogContext
{
    /**
     * @return array<string, mixed>
     */
    public function incomingLogContext(Request $request): array;
}
