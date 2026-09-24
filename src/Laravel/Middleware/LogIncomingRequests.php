<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Laravel\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Milzer\HttpLogger\Core\Direction;
use Milzer\HttpLogger\Core\Exchange;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Laravel\Contracts\ProvidesIncomingLogContext;
use Milzer\HttpLogger\Laravel\Internal\IncomingMessageSerializer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs requests the application receives and the responses it returns.
 *
 * It also starts a trace: the trace id is put into Laravel's Context, so every outgoing Saloon
 * call made while handling the request - and every job it dispatches - carries the same id.
 */
final readonly class LogIncomingRequests
{
    public const TRACE_ID = 'trace_id';

    public function __construct(
        private HttpLogger $logger,
        private Repository $config,
        private Container $container,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...$this->stringList('http-logger.incoming.except'))) {
            return $next($request);
        }

        $traceId = $this->traceId($request);
        Context::add(self::TRACE_ID, $traceId);

        $exchange = $this->logger->exchange(Direction::Incoming, traceId: $traceId);
        $request->attributes->set(Exchange::class, $exchange);

        $exchange->start();
        // Built lazily inside the guarded writer, so even a misconfiguration can't break the request.
        $exchange->request(fn (): array => $this->serializer($exchange)->request($request));

        $response = $next($request);

        $exchange->stop();

        return $response;
    }

    /**
     * Runs after the response has been sent to the client.
     */
    public function terminate(Request $request, Response $response): void
    {
        $exchange = $request->attributes->get(Exchange::class);

        if (! $exchange instanceof Exchange) {
            return;
        }

        $exchange->response($response->getStatusCode(), fn (): array => $this->serializer($exchange)->response($request, $response));
    }

    private function serializer(Exchange $exchange): IncomingMessageSerializer
    {
        $resolver = $this->config->get('http-logger.incoming.context');

        return new IncomingMessageSerializer(
            $exchange,
            $this->container->make(AuthFactory::class),
            is_string($resolver) && $resolver !== '' ? $this->contextResolver($resolver) : null,
        );
    }

    private function contextResolver(string $class): ProvidesIncomingLogContext
    {
        $resolver = $this->container->make($class);

        return $resolver instanceof ProvidesIncomingLogContext
            ? $resolver
            : throw new InvalidArgumentException(sprintf('%s must implement %s.', $class, ProvidesIncomingLogContext::class));
    }

    /**
     * Reuses a trace id sent by the caller (e.g. a gateway's X-Request-Id) when it looks safe,
     * otherwise starts a new one.
     */
    private function traceId(Request $request): string
    {
        $header = $this->config->get('http-logger.incoming.trace_header');
        $incoming = is_string($header) && $header !== '' ? $request->headers->get($header) : null;

        if (is_string($incoming) && preg_match('/^[A-Za-z0-9._:\-]{1,128}$/', $incoming) === 1) {
            return $incoming;
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $value = $this->config->get($key);

        return array_values(array_filter(is_array($value) ? $value : [], is_string(...)));
    }
}
