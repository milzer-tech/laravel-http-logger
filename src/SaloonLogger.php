<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger;

use Closure;
use Milzer\SaloonLogger\Exceptions\MissingLoggerException;
use Milzer\SaloonLogger\Internal\ExchangeLogger;
use Psr\Log\LoggerInterface;
use Saloon\Enums\PipeOrder;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use WeakMap;

/**
 * Entry point: wires the logging middleware into a PendingRequest.
 */
final class SaloonLogger
{
    public const REQUEST_MIDDLEWARE = 'saloonLogger:request';

    public const RESPONSE_MIDDLEWARE = 'saloonLogger:response';

    public const FAILURE_MIDDLEWARE = 'saloonLogger:failure';

    private static self|Closure|null $default = null;

    /** @var WeakMap<PendingRequest, true>|null */
    private static ?WeakMap $registered = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LoggingOptions $options = new LoggingOptions,
    ) {}

    /**
     * Set the instance used by the HasLogging plugin. A closure is resolved lazily on every request,
     * which lets a container (e.g. Laravel's) own the instance.
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

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function options(): LoggingOptions
    {
        return $this->options;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self($logger, $this->options);
    }

    public function withOptions(LoggingOptions $options): self
    {
        return new self($this->logger, $options);
    }

    public function register(PendingRequest $pendingRequest): void
    {
        self::$registered ??= new WeakMap;

        if (isset(self::$registered[$pendingRequest])) {
            return;
        }

        self::$registered[$pendingRequest] = true;

        $exchange = new ExchangeLogger($this->logger, $this->options);

        // Static closures that capture only the exchange: Saloon keeps middleware alive for the
        // lifetime of the PendingRequest, so capturing the connector or request would leak them.
        $pendingRequest->middleware()
            // LAST: log the final request, after auth and all other middleware have modified it.
            ->onRequest(static fn (PendingRequest $request) => $exchange->logRequest($request), self::REQUEST_MIDDLEWARE, PipeOrder::LAST)
            // FIRST: log the raw response and an accurate duration before other middleware runs.
            ->onResponse(static fn (Response $response) => $exchange->logResponse($response), self::RESPONSE_MIDDLEWARE, PipeOrder::FIRST)
            ->onFatalException(static fn (FatalRequestException $exception) => $exchange->logFailure($exception), self::FAILURE_MIDDLEWARE, PipeOrder::FIRST);
    }
}
