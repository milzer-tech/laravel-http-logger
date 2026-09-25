<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Saloon\Internal;

use Milzer\HttpLogger\Core\Direction;
use Milzer\HttpLogger\Core\Exchange;
use Milzer\HttpLogger\Core\Guard;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\Redaction\Redactor;
use Milzer\HttpLogger\Core\Support\Arr;
use Milzer\HttpLogger\Saloon\Contracts\ConfiguresLogging;
use Milzer\HttpLogger\Saloon\Contracts\ProvidesLogContext;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;

/**
 * Adapts one Saloon PendingRequest to a core Exchange. One instance per PendingRequest keeps
 * the correlation id and timer isolated for async, pooled and retried requests.
 *
 * Deliberately holds no reference to the PendingRequest, connector or request.
 *
 * @internal
 */
final class ExchangeLogger
{
    private ?Exchange $exchange = null;

    public function __construct(private readonly HttpLogger $httpLogger) {}

    public function logRequest(PendingRequest $pendingRequest): void
    {
        $options = $this->httpLogger->options();

        Guard::run($this->httpLogger->logger(), $options, Redactor::fromOptions($options), [], function () use ($pendingRequest): void {
            // Booting here rather than in the plugin means connector/request callbacks
            // see the fully built PendingRequest (merged body, headers and auth).
            $exchange = $this->boot($pendingRequest);
            $this->exchange = $exchange;
            // Built now: request body streams may be consumed or closed once the request is sent.
            $exchange->request(fn (): array => (new MessageSerializer($exchange))->request($pendingRequest), buildNow: true);
        });

        // Start the clock as late as possible: after every other request middleware has run.
        $this->exchange?->start();
    }

    public function logResponse(Response $response): void
    {
        $exchange = $this->exchange;

        $exchange?->response($response->status(), static fn (): array => (new MessageSerializer($exchange))->response($response));
    }

    public function logFailure(FatalRequestException $exception): void
    {
        $exchange = $this->exchange;
        $pendingRequest = $exception->getPendingRequest();

        $exchange?->failure(
            $exception->getPrevious() ?? $exception,
            static fn (): array => ['http' => (new MessageSerializer($exchange))->target($pendingRequest)],
        );
    }

    private function boot(PendingRequest $pendingRequest): Exchange
    {
        $options = $this->httpLogger->options();
        $resources = [$pendingRequest->getConnector(), $pendingRequest->getRequest()];

        foreach ($resources as $resource) {
            if ($resource instanceof ConfiguresLogging) {
                $options = $resource->configureLogging($options, $pendingRequest);
            }
        }

        $context = [];

        foreach ($resources as $resource) {
            if ($resource instanceof ProvidesLogContext) {
                $context = Arr::mergeContext($context, $resource->logContext($pendingRequest));
            }
        }

        $connector = $pendingRequest->getConnector()::class;
        $request = $pendingRequest->getRequest()::class;

        return $this->httpLogger->exchange(
            direction: Direction::Outgoing,
            options: $options,
            context: $context,
            meta: ['saloon' => ['connector' => $connector, 'request' => $request]],
            placeholders: ['connector' => $this->basename($connector), 'request' => $this->basename($request)],
        );
    }

    private function basename(string $class): string
    {
        return substr((string) strrchr('\\'.$class, '\\'), 1);
    }
}
