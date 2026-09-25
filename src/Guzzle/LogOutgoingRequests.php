<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Guzzle;

use Closure;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Milzer\HttpLogger\Core\Direction;
use Milzer\HttpLogger\Core\Exchange;
use Milzer\HttpLogger\Core\Guard;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Core\Redaction\Redactor;
use Milzer\HttpLogger\Core\Serialization\PsrMessageSerializer;
use Milzer\HttpLogger\Core\Support\Arr;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle middleware that logs outgoing calls, e.g. made with Laravel's HTTP client:
 *
 *   Http::globalMiddleware(HttpLogger::middleware());                        // every call
 *   Http::withMiddleware(HttpLogger::middleware(['supplier' => 'ratehawk']))  // some calls
 *       ->withOptions(['log_context' => ['action' => 'book']])                // per call
 *       ->post($url, $payload);
 *
 * The HTTP logger is resolved on every request, so the middleware can be registered once at boot.
 */
final readonly class LogOutgoingRequests
{
    /** Request option with properties for this call, merged on top of the middleware's context. */
    public const CONTEXT_OPTION = 'log_context';

    /** Set on the options passed down, so a second instance in the same stack doesn't log again. */
    private const LOGGED_OPTION = 'http_logger.logged';

    /**
     * @param  array<string, mixed>  $context
     * @param  (Closure(LoggingOptions): LoggingOptions)|null  $configure
     */
    public function __construct(
        private array $context = [],
        private ?Closure $configure = null,
    ) {}

    /**
     * @param  callable(RequestInterface, array<array-key, mixed>): PromiseInterface  $handler
     * @return Closure(RequestInterface, array<array-key, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if (($options[self::LOGGED_OPTION] ?? false) === true) {
                return $handler($request, $options);
            }

            $options[self::LOGGED_OPTION] = true;
            $exchange = $this->start($request, $options);

            if (! $exchange instanceof Exchange) {
                return $handler($request, $options);
            }

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $throwable) {
                // Some handlers throw instead of returning a rejected promise.
                $this->logRejection($exchange, $request, $throwable);

                throw $throwable;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($exchange, $request): ResponseInterface {
                    $this->logResponse($exchange, $request, $response);

                    return $response;
                },
                function (mixed $reason) use ($exchange, $request): PromiseInterface {
                    $this->logRejection($exchange, $request, $reason);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * @param  array<array-key, mixed>  $options
     */
    private function start(RequestInterface $request, array $options): ?Exchange
    {
        $logger = HttpLogger::resolve();
        $exchange = null;

        Guard::run($logger->logger(), $logger->options(), Redactor::fromOptions($logger->options()), [], function () use ($logger, $request, $options, &$exchange): void {
            $exchange = $logger->exchange(
                direction: Direction::Outgoing,
                options: $this->configure instanceof Closure ? ($this->configure)($logger->options()) : $logger->options(),
                context: Arr::mergeContext($this->context, $this->requestContext($options)),
            );

            // Built now: the request body stream is consumed once the request is sent.
            $exchange->request(static fn (): array => (new PsrMessageSerializer($exchange))->request($request), buildNow: true);
        });

        // Start the clock right before the request leaves.
        $exchange?->start();

        return $exchange;
    }

    private function logResponse(Exchange $exchange, RequestInterface $request, ResponseInterface $response): void
    {
        $exchange->response(
            $response->getStatusCode(),
            static fn (): array => (new PsrMessageSerializer($exchange))->response($request, $response),
        );
    }

    private function logRejection(Exchange $exchange, RequestInterface $request, mixed $reason): void
    {
        // An error response turned into an exception by an inner middleware is still a response.
        if ($reason instanceof RequestException && $reason->getResponse() instanceof ResponseInterface) {
            $this->logResponse($exchange, $request, $reason->getResponse());

            return;
        }

        if ($reason instanceof Throwable) {
            $exchange->failure($reason, static fn (): array => ['http' => (new PsrMessageSerializer($exchange))->target($request)]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<string, mixed>
     */
    private function requestContext(array $options): array
    {
        $context = [];

        foreach (is_array($options[self::CONTEXT_OPTION] ?? null) ? $options[self::CONTEXT_OPTION] : [] as $key => $value) {
            if (is_string($key)) {
                $context[$key] = $value;
            }
        }

        return $context;
    }
}
