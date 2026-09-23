<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\SaloonLogger;

final class SaloonLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/saloon-logger.php', 'saloon-logger');

        $this->app->singleton(SaloonLogger::class, static function (Application $app): SaloonLogger {
            $config = (array) $app->make(Repository::class)->get('saloon-logger', []);
            $log = $app->make(LogManager::class);

            $config['body_formatters'] = array_map(
                static fn (mixed $formatter): mixed => is_string($formatter) ? $app->make($formatter) : $formatter,
                (array) ($config['body_formatters'] ?? []),
            );

            $channel = $config['channel'] ?? null;

            return new SaloonLogger(
                is_string($channel) && $channel !== '' ? $log->channel($channel) : $log->driver(),
                LoggingOptions::fromArray($config),
            );
        });

        // Resolved lazily so config changes (and test doubles) bound later are respected.
        SaloonLogger::setDefault(fn (): SaloonLogger => $this->app->make(SaloonLogger::class));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/saloon-logger.php' => $this->app->configPath('saloon-logger.php'),
            ], 'saloon-logger-config');
        }
    }
}
