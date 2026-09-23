<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Internal;

use Milzer\SaloonLogger\Contracts\ConfiguresLogging;
use Milzer\SaloonLogger\Contracts\ProvidesLogContext;
use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\Redaction\Redactor;
use Milzer\SaloonLogger\Serialization\MessageSerializer;
use Psr\Log\LoggerInterface;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Stringable;
use Throwable;

/**
 * Logs a single request/response exchange. One instance exists per PendingRequest,
 * which keeps the correlation id and timer isolated for async, pooled and retried requests.
 *
 * Deliberately holds no reference to the PendingRequest, connector or request.
 *
 * @internal
 */
final class ExchangeLogger
{
    private ?LoggingOptions $options = null;

    private ?LoggerInterface $logger = null;

    private ?MessageSerializer $serializer = null;

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array{connector?: class-string, request?: class-string} */
    private array $saloon = [];

    private readonly string $correlationId;

    private ?int $startedAt = null;

    public function __construct(
        private readonly LoggerInterface $defaultLogger,
        private readonly LoggingOptions $defaultOptions,
    ) {
        $this->correlationId = bin2hex(random_bytes(8));
    }

    public function logRequest(PendingRequest $pendingRequest): void
    {
        $this->guard(function () use ($pendingRequest): void {
            // Booting here rather than in the plugin means connector/request callbacks
            // see the fully built PendingRequest (merged body, headers and auth).
            $this->boot($pendingRequest);

            if (! $this->isEnabled() || ! $this->active()->logRequests) {
                return;
            }

            $http = $this->serializer()->request($pendingRequest);

            $this->write($this->active()->requestLevel, $this->active()->requestMessage, ['http' => $http], $http);
        });

        // Start the clock as late as possible: after every other request middleware has run.
        $this->startedAt = hrtime(true);
    }

    public function logResponse(Response $response): void
    {
        $duration = $this->elapsed();

        $this->guard(function () use ($response, $duration): void {
            if (! $this->isEnabled() || ! $this->active()->logResponses) {
                return;
            }

            $options = $this->active();
            $status = $response->status();
            $http = $this->serializer()->response($response);

            $level = match (true) {
                $status >= 500 => $options->serverErrorLevel,
                $status >= 400 => $options->clientErrorLevel,
                default => $options->responseLevel,
            };

            $this->write($level, $options->responseMessage, array_filter([
                'http' => $http,
                'response_time_in_seconds' => $duration,
                'mocked' => $response->isMocked() ?: null,
                'cached' => $response->isCached() ?: null,
            ], static fn (mixed $value): bool => $value !== null), $http);
        });
    }

    public function logFailure(FatalRequestException $exception): void
    {
        $duration = $this->elapsed();

        $this->guard(function () use ($exception, $duration): void {
            if (! $this->isEnabled() || ! $this->active()->logFailures) {
                return;
            }

            $pendingRequest = $exception->getPendingRequest();
            $cause = $exception->getPrevious() ?? $exception;
            $http = $this->serializer()->target($pendingRequest->getMethod()->value, $pendingRequest->getUri());

            $this->write($this->active()->failureLevel, $this->active()->failureMessage, [
                'http' => $http,
                'response_time_in_seconds' => $duration,
                'error' => [
                    'type' => $cause::class,
                    'message' => $cause->getMessage(),
                    'code' => $cause->getCode(),
                ],
                'exception' => $cause,
            ], $http);
        });
    }

    private function boot(PendingRequest $pendingRequest): void
    {
        $options = $this->defaultOptions;
        $resources = [$pendingRequest->getConnector(), $pendingRequest->getRequest()];

        foreach ($resources as $resource) {
            if ($resource instanceof ConfiguresLogging) {
                $options = $resource->configureLogging($options, $pendingRequest);
            }
        }

        $context = $options->context;

        foreach ($resources as $resource) {
            if ($resource instanceof ProvidesLogContext) {
                $context = self::mergeContext($context, $resource->logContext($pendingRequest));
            }
        }

        $this->options = $options;
        $this->logger = $options->logger ?? $this->defaultLogger;
        $this->context = $context;
        $this->saloon = [
            'connector' => $pendingRequest->getConnector()::class,
            'request' => $pendingRequest->getRequest()::class,
        ];
        $this->serializer = new MessageSerializer(
            $options,
            new Redactor($options->redactHeaders, $options->redactKeys, $options->redactionMask),
        );
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function mergeContext(array $base, array $extra): array
    {
        foreach ($extra as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key])
                ? array_replace_recursive($base[$key], $value)
                : $value;
        }

        return $base;
    }

    private function isEnabled(): bool
    {
        return $this->options?->enabled === true;
    }

    private function active(): LoggingOptions
    {
        return $this->options ?? $this->defaultOptions;
    }

    private function serializer(): MessageSerializer
    {
        return $this->serializer ?? throw new \LogicException('Exchange logger used before it was booted.');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $http
     */
    private function write(string $level, string $message, array $entry, array $http): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        ($this->logger ?? $this->defaultLogger)->log($level, $this->interpolate($message, $http), [
            ...$this->context,
            ...$entry,
            'correlation_id' => $this->correlationId,
            'saloon' => $this->saloon,
        ]);
    }

    /**
     * Resolves {placeholders} in a message from the context, the Saloon classes and the http section.
     *
     * @param  array<string, mixed>  $http
     */
    private function interpolate(string $message, array $http): string
    {
        if (! str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];

        foreach ($this->context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        foreach ($this->saloon as $key => $class) {
            $replacements['{'.$key.'}'] = substr((string) strrchr('\\'.$class, '\\'), 1);
        }

        foreach (['method', 'url', 'status'] as $key) {
            if (isset($http[$key]) && is_scalar($http[$key])) {
                $replacements['{'.$key.'}'] = (string) $http[$key];
            }
        }

        return strtr($message, $replacements);
    }

    private function elapsed(): ?float
    {
        return $this->startedAt === null ? null : round((hrtime(true) - $this->startedAt) / 1e9, 3);
    }

    /**
     * Logging must never break the HTTP call it observes (unless explicitly asked to, e.g. in tests).
     */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            if ($this->active()->throwOnError) {
                throw $exception;
            }

            try {
                ($this->logger ?? $this->defaultLogger)->error('saloon-logger failed to write a log entry', [
                    'correlation_id' => $this->correlationId,
                    'saloon' => $this->saloon,
                    'exception' => $exception,
                ]);
            } catch (Throwable) {
                error_log(sprintf('[saloon-logger] %s: %s', $exception::class, $exception->getMessage()));
            }
        }
    }
}
