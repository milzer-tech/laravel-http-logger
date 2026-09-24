<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Contracts;

use Closure;

/**
 * Decides *when* a log entry is built and written: immediately, or later
 * (e.g. after the HTTP response has been sent to the client).
 */
interface Writer
{
    /**
     * @param  Closure(): void  $callback  Builds and writes one log entry.
     */
    public function write(Closure $callback): void;
}
