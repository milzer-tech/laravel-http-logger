<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Guzzle\LogOutgoingRequests;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A Guzzle client whose stack is: Guzzle defaults -> the given middleware -> $handler.
 *
 * @param  list<callable>  $middleware
 */
function guzzle(callable $handler, array $middleware = []): Client
{
    $stack = HandlerStack::create($handler);

    foreach ($middleware === [] ? [HttpLogger::middleware()] : $middleware as $entry) {
        $stack->push($entry);
    }

    return new Client(['handler' => $stack, 'http_errors' => false]);
}

/**
 * Sends a request straight through a handler stack and returns the promise.
 */
function sendThrough(HandlerStack $stack, Request $request): PromiseInterface
{
    $result = $stack($request, []);

    return $result instanceof PromiseInterface ? $result : throw new LogicException('Expected a promise.');
}

it('logs the request and the response', function (): void {
    $client = guzzle(new MockHandler([new Response(201, ['Content-Type' => 'application/json'], '{"booking_id":"B-1","access_token":"tok"}')]));

    $client->post('https://api.supplier.test/v1/bookings?lang=de&api_key=secret', [
        'json' => ['hotel' => 'H1', 'password' => 'hunter2'],
        'headers' => ['Authorization' => 'Bearer x'],
    ]);

    [$request, $response] = testLog()->records;

    expect($request->message)->toBe('outgoing-request')
        ->and($request->get('direction'))->toBe('outgoing')
        ->and($request->get('http.method'))->toBe('POST')
        ->and($request->get('http.url'))->toBe('https://api.supplier.test/v1/bookings')
        ->and($request->get('http.queries'))->toBe(['lang' => 'de', 'api_key' => '[REDACTED]'])
        ->and($request->get('http.headers.Authorization'))->toBe('[REDACTED]')
        ->and($request->get('http.headers.Content-Type'))->toBe('application/json')
        ->and($request->get('http.body'))->toBe(['hotel' => 'H1', 'password' => '[REDACTED]'])
        ->and($request->has('saloon'))->toBeFalse();

    expect($response->message)->toBe('outgoing-response')
        ->and($response->get('http.status'))->toBe(201)
        ->and($response->get('http.body'))->toBe(['booking_id' => 'B-1', 'access_token' => '[REDACTED]'])
        ->and($response->get('response_time_in_seconds'))->toBeFloat()
        ->and($response->get('correlation_id'))->toBe($request->get('correlation_id'));
});

it('merges the middleware context with the per-call log_context option', function (): void {
    $client = guzzle(new MockHandler([new Response]), [HttpLogger::middleware(['supplier' => 'ratehawk', 'api' => 'hotels'])]);

    $client->get('https://api.supplier.test', ['log_context' => ['api' => 'bookings', 'action' => 'book', 0 => 'ignored']]);

    expect(testLog()->record(0)->context)->toMatchArray(['supplier' => 'ratehawk', 'api' => 'bookings', 'action' => 'book'])
        ->and(testLog()->record(0)->has('0'))->toBeFalse();
});

it('ignores a log_context option that is not an array', function (): void {
    guzzle(new MockHandler([new Response]))->get('https://api.supplier.test', ['log_context' => 'nope']);

    expect(testLog()->records)->toHaveCount(2);
});

it('lets the middleware adjust options such as messages', function (): void {
    $middleware = HttpLogger::middleware(
        ['supplier' => 'ratehawk'],
        fn (LoggingOptions $options): LoggingOptions => $options->withOutgoingMessages(request: 'checkout-to-{supplier}', response: '{supplier}-to-checkout {status}'),
    );

    guzzle(new MockHandler([new Response(204)]), [$middleware])->get('https://api.supplier.test');

    expect(testLog()->record(0)->message)->toBe('checkout-to-ratehawk')
        ->and(testLog()->record(1)->message)->toBe('ratehawk-to-checkout 204');
});

it('logs once when the middleware is in the stack twice', function (): void {
    guzzle(new MockHandler([new Response]), [HttpLogger::middleware(), HttpLogger::middleware()])->get('https://api.supplier.test');

    expect(testLog()->records)->toHaveCount(2);
});

it('logs connection failures with a masked message and keeps the rejection', function (): void {
    $handler = fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
        new ConnectException('cURL error 7: refused for https://api.supplier.test/?api_key=sk_live_1', $request),
    );

    expect(fn (): ResponseInterface => guzzle($handler)->get('https://api.supplier.test/?api_key=sk_live_1'))->toThrow(ConnectException::class);

    $failure = testLog()->record(1);

    expect($failure->message)->toBe('outgoing-failure')
        ->and($failure->level)->toBe('error')
        ->and($failure->get('http'))->toBe(['method' => 'GET', 'url' => 'https://api.supplier.test/'])
        ->and($failure->get('error.type'))->toBe(ConnectException::class)
        ->and($failure->get('error.message'))->toBe('cURL error 7: refused for https://api.supplier.test/?api_key=%5BREDACTED%5D');
});

it('logs failures from handlers that throw instead of rejecting', function (): void {
    $handler = function (RequestInterface $request): never {
        throw new ConnectException('HTTP/3 is not supported', $request);
    };

    expect(fn (): ResponseInterface => guzzle($handler)->get('https://api.supplier.test'))->toThrow(ConnectException::class, 'HTTP/3 is not supported');

    expect(testLog()->record(1)->message)->toBe('outgoing-failure');
});

it('logs error responses wrapped in a RequestException as responses', function (): void {
    $handler = fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
        new RequestException('Server error', $request, new Response(503, [], 'down')),
    );

    expect(fn (): ResponseInterface => guzzle($handler)->get('https://api.supplier.test'))->toThrow(RequestException::class);

    expect(testLog()->record(1)->message)->toBe('outgoing-response')
        ->and(testLog()->record(1)->level)->toBe('error')
        ->and(testLog()->record(1)->get('http.status'))->toBe(503)
        ->and(testLog()->record(1)->get('http.body'))->toBe('down');
});

it('passes on rejections that are not exceptions without logging a failure', function (): void {
    $stack = HandlerStack::create(fn (): PromiseInterface => Create::rejectionFor('boom'));
    $stack->push(new LogOutgoingRequests);

    $reason = null;
    sendThrough($stack, new Request('GET', 'https://api.supplier.test'))->otherwise(function (mixed $value) use (&$reason): void {
        $reason = $value;
    })->wait();

    expect($reason)->toBe('boom')
        ->and(testLog()->records)->toHaveCount(1);
});

it('never breaks the call when the logging setup fails', function (): void {
    useOptions(new LoggingOptions);

    $middleware = HttpLogger::middleware(configure: fn (): LoggingOptions => throw new RuntimeException('bad setup'));

    $response = guzzle(new MockHandler([new Response(200, [], 'ok')]), [$middleware])->get('https://api.supplier.test');

    expect((string) $response->getBody())->toBe('ok')
        ->and(testLog()->records)->toHaveCount(1)
        ->and(testLog()->record(0)->message)->toBe('http-logger failed to write a log entry');
});

it('describes multipart request bodies instead of reading the files', function (): void {
    guzzle(new MockHandler([new Response]))->post('https://api.supplier.test/upload', [
        'multipart' => [
            ['name' => 'title', 'contents' => 'Voucher'],
            ['name' => 'file', 'contents' => str_repeat('%PDF', 256), 'filename' => 'voucher.pdf'],
        ],
    ]);

    expect(testLog()->record(0)->get('http.body'))->toStartWith('[multipart body omitted: multipart/form-data, 1.')
        ->toEndWith(' KB]');
});

it('describes multipart bodies of unknown size', function (): void {
    $request = new Request('POST', 'https://api.supplier.test', ['Content-Type' => 'multipart/form-data; boundary=x'], FnStream::decorate(Utils::streamFor('--x--'), ['getSize' => fn (): ?int => null]));

    $stack = HandlerStack::create(new MockHandler([new Response]));
    $stack->push(new LogOutgoingRequests);

    sendThrough($stack, $request)->wait();

    expect(testLog()->record(0)->get('http.body'))->toBe('[multipart body omitted: multipart/form-data]');
});
