<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Milzer\HttpLogger\Core\Contracts\Writer;

/**
 * Builds and writes entries after the HTTP response has been sent, so logging adds no latency
 * for the client. Entries keep the order in which they were produced.
 *
 * In the console (artisan commands, queue workers) "after the response" would mean "after the
 * whole command or worker", so entries are written immediately there.
 */
final readonly class DeferredWriter implements Writer
{
    public function __construct(private Application $app) {}

    public function write(Closure $callback): void
    {
        if ($this->app->runningInConsole()) {
            $callback();

            return;
        }

        $this->app->terminating($callback);
    }
}
