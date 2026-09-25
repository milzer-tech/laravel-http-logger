<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core;

use Closure;
use Milzer\HttpLogger\Core\Contracts\BodyStore;
use Milzer\HttpLogger\Core\Contracts\Writer;
use Milzer\HttpLogger\Core\Exceptions\MissingLoggerException;
use Milzer\HttpLogger\Core\Support\Arr;
use Milzer\HttpLogger\Core\Writers\ImmediateWriter;
use Milzer\HttpLogger\Guzzle\LogOutgoingRequests;
use Psr\Log\LoggerInterface;

/**
 * Entry point shared by all integrations: holds the PSR-3 logger, the options, the writer
 * (when entries are written) and the optional store for large bodies.
 */
final class HttpLogger
{
    private static self|Closure|null $default = null;

    private static ?Closure $traceIdResolver = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LoggingOptions $options = new LoggingOptions,
        private readonly Writer $writer = new ImmediateWriter,
        private readonly ?BodyStore $store = null,
    ) {}

    /**
     * Sets the instance used by the Saloon plugin. A closure is resolved lazily on every
     * request, which lets a container (e.g. Laravel's) own the instance.
     *
     * @param  self|(Closure(): self)|null  $logger
     */
    public static function setDefault(self|Closure|null $logger): void
    {
        self::$default = $logger;
    }

    public static function resolve(): self
    {
        $default = self::$default instanceof Closure ? (self::$default)() : self::$default;

        return $default instanceof self ? $default : throw MissingLoggerException::make();
    }

    /**
     * Tells the package where the current trace id lives (e.g. Laravel's Context), so outgoing
     * calls made while handling an incoming request share its trace id.
     *
     * @param  (Closure(): mixed)|null  $resolver
     */
    public static function resolveTraceIdUsing(?Closure $resolver): void
    {
        self::$traceIdResolver = $resolver;
    }

    /**
     * Guzzle middleware for Laravel's HTTP client (or any Guzzle client):
     *
     *   Http::globalMiddleware(HttpLogger::middleware());
     *   Http::withMiddleware(HttpLogger::middleware(['supplier' => 'ratehawk']))->post(...);
     *
     * @param  array<string, mixed>  $context  Added to every entry this middleware writes.
     * @param  (Closure(LoggingOptions): LoggingOptions)|null  $configure  Adjust options, e.g. messages.
     */
    public static function middleware(array $context = [], ?Closure $configure = null): LogOutgoingRequests
    {
        return new LogOutgoingRequests($context, $configure);
    }

    public static function traceId(): ?string
    {
        $traceId = self::$traceIdResolver instanceof Closure ? (self::$traceIdResolver)() : null;

        return is_string($traceId) && $traceId !== '' ? $traceId : null;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function options(): LoggingOptions
    {
        return $this->options;
    }

    public function writer(): Writer
    {
        return $this->writer;
    }

    public function store(): ?BodyStore
    {
        return $this->store;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self($logger, $this->options, $this->writer, $this->store);
    }

    public function withOptions(LoggingOptions $options): self
    {
        return new self($this->logger, $options, $this->writer, $this->store);
    }

    public function withWriter(Writer $writer): self
    {
        return new self($this->logger, $this->options, $writer, $this->store);
    }

    public function withStore(?BodyStore $store): self
    {
        return new self($this->logger, $this->options, $this->writer, $store);
    }

    /**
     * Starts logging one request/response pair.
     *
     * @param  array<string, mixed>  $context  Merged on top of the options' static context.
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $placeholders
     */
    public function exchange(
        Direction $direction,
        ?LoggingOptions $options = null,
        array $context = [],
        array $meta = [],
        array $placeholders = [],
        ?string $traceId = null,
    ): Exchange {
        $options ??= $this->options;

        return new Exchange(
            logger: $options->logger ?? $this->logger,
            options: $options,
            writer: $this->writer,
            direction: $direction,
            context: Arr::mergeContext($options->context, $context),
            meta: $meta,
            placeholders: $placeholders,
            store: $this->store,
            traceId: $traceId ?? self::traceId(),
        );
    }
}
