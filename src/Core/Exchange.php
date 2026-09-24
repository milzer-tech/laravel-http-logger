<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core;

use Closure;
use DateTimeImmutable;
use Milzer\HttpLogger\Core\Contracts\BodyStore;
use Milzer\HttpLogger\Core\Contracts\Writer;
use Milzer\HttpLogger\Core\Redaction\Redactor;
use Milzer\HttpLogger\Core\Serialization\BodyContext;
use Milzer\HttpLogger\Core\Serialization\BodySerializer;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * One request/response pair, in either direction. Integrations (Saloon, Laravel) turn their
 * framework objects into "entry parts"; the exchange adds the shared fields, picks the level
 * and message, and hands the entry to the writer.
 *
 * Entry parts are passed as closures so a deferred writer can build them after the response
 * has been sent, keeping that work off the request path.
 */
final class Exchange
{
    public readonly string $correlationId;

    private readonly Redactor $redactor;

    private readonly BodySerializer $bodies;

    private ?int $startedAt = null;

    private ?float $duration = null;

    /**
     * @param  array<string, mixed>  $context  User context, first in every entry.
     * @param  array<string, mixed>  $meta  Integration metadata, e.g. ['saloon' => [...]].
     * @param  array<string, string>  $placeholders  Extra message placeholders, e.g. ['connector' => 'RatehawkConnector'].
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LoggingOptions $options,
        private readonly Writer $writer,
        public readonly Direction $direction,
        private readonly array $context = [],
        private readonly array $meta = [],
        private readonly array $placeholders = [],
        ?BodyStore $store = null,
        private readonly ?string $traceId = null,
    ) {
        $this->correlationId = bin2hex(random_bytes(8));
        $this->redactor = new Redactor($options->redactHeaders, $options->redactKeys, $options->redactionMask);
        $this->bodies = new BodySerializer(
            $this->redactor,
            $options->maxBodyBytes,
            $options->maxParseBytes,
            $options->bodyFormatters,
            $options->storeLargeBodies ? $store : null,
            $options->maxStoredBodyBytes,
        );
    }

    public function options(): LoggingOptions
    {
        return $this->options;
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    public function bodies(): BodySerializer
    {
        return $this->bodies;
    }

    /**
     * @param  'request'|'response'  $part
     */
    public function bodyContext(string $part): BodyContext
    {
        return new BodyContext($this->correlationId, $this->direction, $part, new DateTimeImmutable);
    }

    public function start(): void
    {
        $this->startedAt = hrtime(true);
        $this->duration = null;
    }

    /**
     * Freezes the duration, e.g. when the response is ready but logged later.
     */
    public function stop(): void
    {
        $this->duration = $this->elapsed();
    }

    /**
     * @param  Closure(): array<string, mixed>  $parts
     * @param  bool  $buildNow  Build the parts immediately (e.g. while request streams are still readable).
     */
    public function request(Closure $parts, bool $buildNow = false): void
    {
        if (! $this->options->enabled || ! $this->options->logRequests) {
            return;
        }

        if ($buildNow) {
            $built = $parts();
            $parts = static fn (): array => $built;
        }

        $message = $this->direction === Direction::Incoming
            ? $this->options->incomingRequestMessage
            : $this->options->outgoingRequestMessage;

        $this->dispatch($this->options->requestLevel, $message, $parts, []);
    }

    /**
     * @param  Closure(): array<string, mixed>  $parts
     */
    public function response(int $status, Closure $parts): void
    {
        if (! $this->options->enabled || ! $this->options->logResponses) {
            return;
        }

        $level = match (true) {
            $status >= 500 => $this->options->serverErrorLevel,
            $status >= 400 => $this->options->clientErrorLevel,
            default => $this->options->responseLevel,
        };

        $message = $this->direction === Direction::Incoming
            ? $this->options->incomingResponseMessage
            : $this->options->outgoingResponseMessage;

        $this->dispatch($level, $message, $parts, ['response_time_in_seconds' => $this->elapsed()]);
    }

    /**
     * A call that got no response at all (connection error, timeout, DNS, TLS).
     *
     * @param  Closure(): array<string, mixed>  $parts
     */
    public function failure(Throwable $cause, Closure $parts): void
    {
        if (! $this->options->enabled || ! $this->options->logFailures) {
            return;
        }

        $this->dispatch($this->options->failureLevel, $this->options->outgoingFailureMessage, $parts, [
            'response_time_in_seconds' => $this->elapsed(),
            'error' => [
                'type' => $cause::class,
                'message' => $cause->getMessage(),
                'code' => $cause->getCode(),
            ],
            'exception' => $cause,
        ]);
    }

    /**
     * @param  Closure(): void  $callback
     */
    public function guard(Closure $callback): void
    {
        Guard::run($this->logger, $this->options->throwOnError, [
            'direction' => $this->direction->value,
            'correlation_id' => $this->correlationId,
        ], $callback);
    }

    /**
     * @param  Closure(): array<string, mixed>  $parts
     * @param  array<string, mixed>  $extra
     */
    private function dispatch(string $level, string $message, Closure $parts, array $extra): void
    {
        // Captured now, at the moment of the event, even if the entry is written later.
        $occurredAt = (new DateTimeImmutable)->format('Y-m-d\TH:i:s.uP');

        $this->writer->write(fn () => $this->guard(function () use ($level, $message, $parts, $extra, $occurredAt): void {
            $context = [
                ...$this->context,
                ...$parts(),
                ...array_filter($extra, static fn (mixed $value): bool => $value !== null),
                'direction' => $this->direction->value,
                'correlation_id' => $this->correlationId,
                ...($this->traceId === null ? [] : ['trace_id' => $this->traceId]),
                'occurred_at' => $occurredAt,
                ...$this->meta,
            ];

            $this->logger->log($level, $this->interpolate($message, $context), $context);
        }));
    }

    private function elapsed(): ?float
    {
        if ($this->duration !== null || $this->startedAt === null) {
            return $this->duration;
        }

        return round((hrtime(true) - $this->startedAt) / 1e9, 3);
    }

    /**
     * Resolves {placeholders} from scalar top-level context values, the integration's
     * placeholders and the http section (method, url, status, route).
     *
     * @param  array<array-key, mixed>  $context
     */
    private function interpolate(string $message, array $context): string
    {
        if (! str_contains($message, '{')) {
            return $message;
        }

        $http = is_array($context['http'] ?? null) ? $context['http'] : [];
        $replacements = [];

        foreach ([...$context, ...$this->placeholders, ...$http] as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
