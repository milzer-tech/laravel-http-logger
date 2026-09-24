<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Milzer\HttpLogger\Core\Contracts\BodyStore;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Core\Writers\ImmediateWriter;
use Milzer\HttpLogger\Laravel\Commands\PruneStoredBodiesCommand;
use Milzer\HttpLogger\Laravel\Middleware\LogIncomingRequests;
use Milzer\HttpLogger\Storage\FilesystemBodyStore;

final class HttpLoggerServiceProvider extends ServiceProvider
{
    public const MIDDLEWARE_ALIAS = 'http-logger';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/http-logger.php', 'http-logger');

        $this->app->singleton(HttpLogger::class, static function (Application $app): HttpLogger {
            $config = (array) $app->make(Repository::class)->get('http-logger', []);
            $log = $app->make(LogManager::class);

            $config['body_formatters'] = array_map(
                static fn (mixed $formatter): mixed => is_string($formatter) ? $app->make($formatter) : $formatter,
                (array) ($config['body_formatters'] ?? []),
            );

            $channel = $config['channel'] ?? null;

            return new HttpLogger(
                logger: is_string($channel) && $channel !== '' ? $log->channel($channel) : $log->driver(),
                options: LoggingOptions::fromArray($config),
                writer: ($config['write_after_response'] ?? true) ? new DeferredWriter($app) : new ImmediateWriter,
                store: self::store($app, (array) ($config['storage'] ?? [])),
            );
        });

        // Resolved lazily so config changes (and test doubles) bound later are respected.
        HttpLogger::setDefault(fn (): HttpLogger => $this->app->make(HttpLogger::class));
        HttpLogger::resolveTraceIdUsing(static fn (): mixed => Context::get(LogIncomingRequests::TRACE_ID));
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware(self::MIDDLEWARE_ALIAS, LogIncomingRequests::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/http-logger.php' => $this->app->configPath('http-logger.php'),
            ], 'http-logger-config');

            $this->commands([PruneStoredBodiesCommand::class]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function store(Application $app, array $config): ?BodyStore
    {
        if (! ($config['enabled'] ?? false)) {
            return null;
        }

        $name = is_string($config['disk'] ?? null) ? $config['disk'] : null;
        $disk = $app->make(FilesystemManager::class)->disk($name);

        if (! $disk instanceof FilesystemAdapter) {
            throw new InvalidArgumentException(sprintf('The http-logger storage disk "%s" must be a Flysystem-based disk.', $name ?? 'default'));
        }

        return new FilesystemBodyStore(
            $disk->getDriver(),
            $name ?? 'default',
            is_string($config['path'] ?? null) ? $config['path'] : 'http-logs',
        );
    }
}
