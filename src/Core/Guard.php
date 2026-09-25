<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core;

use Closure;
use Milzer\HttpLogger\Core\Redaction\Redactor;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logging must never break the application it observes (unless explicitly asked to, e.g. in tests).
 *
 * @internal
 */
final class Guard
{
    /**
     * @param  array<string, mixed>  $context  Added to the error entry if the callback fails.
     * @param  Closure(): void  $callback
     */
    public static function run(LoggerInterface $logger, LoggingOptions $options, Redactor $redactor, array $context, Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            if ($options->throwOnError) {
                throw $throwable;
            }

            try {
                $logger->error('http-logger failed to write a log entry', [
                    ...$context,
                    ...ErrorContext::from($throwable, $redactor, $options->logExceptionObject),
                ]);
            } catch (Throwable) {
                error_log(sprintf('[http-logger] %s: %s', $throwable::class, $redactor->string($throwable->getMessage())));
            }
        }
    }
}
