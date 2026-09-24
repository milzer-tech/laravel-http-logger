<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Saloon;

use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Saloon\Internal\ExchangeLogger;
use Saloon\Enums\PipeOrder;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use WeakMap;

/**
 * Wires the logging middleware into a PendingRequest.
 */
final class SaloonLogging
{
    public const REQUEST_MIDDLEWARE = 'httpLogger:request';

    public const RESPONSE_MIDDLEWARE = 'httpLogger:response';

    public const FAILURE_MIDDLEWARE = 'httpLogger:failure';

    /** @var WeakMap<PendingRequest, true>|null */
    private static ?WeakMap $registered = null;

    public static function register(HttpLogger $logger, PendingRequest $pendingRequest): void
    {
        self::$registered ??= new WeakMap;

        if (isset(self::$registered[$pendingRequest])) {
            return;
        }

        self::$registered[$pendingRequest] = true;

        $exchange = new ExchangeLogger($logger);

        // Static closures that capture only the exchange logger: Saloon keeps middleware alive for
        // the lifetime of the PendingRequest, so capturing the connector or request would leak them.
        $pendingRequest->middleware()
            // LAST: log the final request, after auth and all other middleware have modified it.
            ->onRequest(static fn (PendingRequest $request) => $exchange->logRequest($request), self::REQUEST_MIDDLEWARE, PipeOrder::LAST)
            // FIRST: log the raw response and an accurate duration before other middleware runs.
            ->onResponse(static fn (Response $response) => $exchange->logResponse($response), self::RESPONSE_MIDDLEWARE, PipeOrder::FIRST)
            ->onFatalException(static fn (FatalRequestException $exception) => $exchange->logFailure($exception), self::FAILURE_MIDDLEWARE, PipeOrder::FIRST);
    }
}
