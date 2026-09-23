<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use Milzer\SaloonLogger\Exceptions\MissingLoggerException;
use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\Plugins\HasLogging;
use Milzer\SaloonLogger\SaloonLogger;
use Milzer\SaloonLogger\Tests\Support\ArrayLogger;
use Milzer\SaloonLogger\Tests\Support\FormRequest;
use Milzer\SaloonLogger\Tests\Support\GetBookingRequest;
use Milzer\SaloonLogger\Tests\Support\LoggedRequest;
use Milzer\SaloonLogger\Tests\Support\SearchRequest;
use Milzer\SaloonLogger\Tests\Support\StreamRequest;
use Milzer\SaloonLogger\Tests\Support\TestConnector;
use Milzer\SaloonLogger\Tests\Support\UploadRequest;
use Milzer\SaloonLogger\Tests\Support\XmlRequest;
use Psr\Log\LogLevel;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Connector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

function connector(MockResponse ...$responses): TestConnector
{
    return (new TestConnector)->withMockClient(new MockClient($responses ?: [MockResponse::make()]));
}

it('logs the request and the response as two correlated entries', function (): void {
    connector(MockResponse::make(['offers' => [['id' => 1]]], 200, ['Content-Type' => 'application/json']))
        ->send(new SearchRequest);

    expect($this->logger->records)->toHaveCount(2);

    [$request, $response] = $this->logger->records;

    expect($request['level'])->toBe(LogLevel::INFO)
        ->and($request['message'])->toBe('saloon-request')
        ->and($request['context'])->toMatchArray([
            'project' => 'checkout',
            'supplier' => 'ratehawk',
            'api' => 'accommodations',
            'action' => 'search',
        ])
        ->and($request['context']['http'])->toMatchArray([
            'method' => 'POST',
            'url' => 'https://api.supplier.test/v1/accommodations/search',
            'queries' => ['lang' => 'de', 'api_key' => '[REDACTED]'],
        ])
        ->and($request['context']['saloon'])->toBe([
            'connector' => TestConnector::class,
            'request' => SearchRequest::class,
        ]);

    expect($response['message'])->toBe('saloon-response')
        ->and($response['context']['http']['status'])->toBe(200)
        ->and($response['context']['http']['body'])->toBe(['offers' => [['id' => 1]]])
        ->and($response['context']['response_time_in_seconds'])->toBeFloat()
        ->and($response['context']['mocked'])->toBeTrue()
        ->and($response['context']['correlation_id'])->toBe($request['context']['correlation_id']);
});

it('logs the final request including headers added by authenticators', function (): void {
    connector()->send(new SearchRequest);

    $headers = $this->logger->record(0)['context']['http']['headers'];

    expect($headers['Authorization'])->toBe('[REDACTED]')
        ->and($headers['Content-Type'])->toBe('application/json');
});

it('decodes and redacts JSON request bodies', function (): void {
    connector()->send(new SearchRequest);

    expect($this->logger->record(0)['context']['http']['body'])->toBe([
        'destination' => 'Mallorca',
        'guests' => [['name' => 'Jane', 'passport' => 'X123']],
        'payment' => ['cardNumber' => '[REDACTED]', 'cvv' => '[REDACTED]', 'holder' => 'Jane Doe'],
    ]);
});

it('keeps XML as a string and masks sensitive elements and attributes', function (): void {
    connector(MockResponse::make('<Result><Token>abc</Token><Status>OK</Status></Result>', 200, ['Content-Type' => 'text/xml; charset=utf-8']))
        ->send(new XmlRequest);

    expect($this->logger->record(0)['context']['http']['body'])
        ->toBe('<?xml version="1.0"?><Envelope><Auth user="bob" password="[REDACTED]"/><ns:Password>[REDACTED]</ns:Password><Hotel>Palma</Hotel></Envelope>')
        ->and($this->logger->record(1)['context']['http']['body'])
        ->toBe('<Result><Token>[REDACTED]</Token><Status>OK</Status></Result>');
});

it('parses and redacts form bodies', function (): void {
    connector(MockResponse::make('access_token=xyz&expires_in=3600', 200, ['Content-Type' => 'application/x-www-form-urlencoded']))
        ->send(new FormRequest);

    expect($this->logger->record(0)['context']['http']['body'])
        ->toBe(['grant_type' => 'client_credentials', 'client_id' => 'abc', 'client_secret' => '[REDACTED]'])
        ->and($this->logger->record(1)['context']['http']['body'])
        ->toBe(['access_token' => '[REDACTED]', 'expires_in' => '3600']);
});

it('summarises multipart bodies without dumping files', function (): void {
    connector()->send(new UploadRequest);

    expect($this->logger->record(0)['context']['http']['body'])->toBe([
        ['name' => 'title', 'contents' => 'Voucher'],
        ['name' => 'password', 'contents' => '[REDACTED]'],
        ['name' => 'file', 'filename' => 'voucher.pdf', 'contents' => '[file omitted: 1 KB]'],
    ]);
});

it('reads seekable request streams without consuming them', function (): void {
    $stream = Utils::streamFor('{"a":1}');
    $stream->seek(3);

    $request = new StreamRequest($stream);
    $request->headers()->add('Content-Type', 'application/json');

    connector()->send($request);

    expect($this->logger->record(0)['context']['http']['body'])->toBe(['a' => 1])
        ->and($stream->tell())->toBe(3);
});

it('never reads non-seekable streams', function (): void {
    $stream = new NoSeekStream(Utils::streamFor('secret-data'));

    connector()->send(new StreamRequest($stream));

    expect($this->logger->record(0)['context']['http']['body'])->toBe('[body omitted: non-seekable stream, 11 B]')
        ->and($stream->getContents())->toBe('secret-data');
});

it('describes binary responses instead of logging them', function (string $contentType, string $body, string $expected): void {
    connector(MockResponse::make($body, 200, ['Content-Type' => $contentType]))->send(new GetBookingRequest);

    expect($this->logger->record(1)['context']['http']['body'])->toBe($expected);
})->with([
    'pdf' => ['application/pdf', '%PDF-1.7 ...', '[binary body omitted: application/pdf, 12 B]'],
    'image' => ['image/png', "\x89PNG\r\n", '[binary body omitted: image/png, 6 B]'],
    'invalid utf-8 text' => ['text/plain', "caf\xE9", '[binary body omitted: text/plain, 4 B]'],
]);

it('handles text, html, empty and mislabelled JSON bodies', function (string $contentType, string $body, mixed $expected): void {
    connector(MockResponse::make($body, 200, $contentType === '' ? [] : ['Content-Type' => $contentType]))->send(new GetBookingRequest);

    expect($this->logger->record(1)['context']['http']['body'])->toBe($expected);
})->with([
    'text' => ['text/plain', 'pong', 'pong'],
    'html' => ['text/html', '<h1>Bad Gateway</h1>', '<h1>Bad Gateway</h1>'],
    'empty' => ['application/json', '', null],
    'json as text/html' => ['text/html', '{"ok":true}', ['ok' => true]],
    'json without content type' => ['', '[1,2]', [1, 2]],
    'invalid json' => ['application/json', '{"password":"x", broken', '{"password":"[REDACTED]", broken'],
    'problem+json' => ['application/problem+json', '{"title":"Nope"}', ['title' => 'Nope']],
]);

it('leaves the response body readable for the application', function (): void {
    $response = connector(MockResponse::make(['id' => 7]))->send(new GetBookingRequest);

    expect($response->json())->toBe(['id' => 7]);
});

it('uses warning for 4xx and error for 5xx responses', function (int $status, string $level): void {
    connector(MockResponse::make([], $status))->send(new GetBookingRequest);

    expect($this->logger->record(1)['level'])->toBe($level);
})->with([
    [200, LogLevel::INFO],
    [302, LogLevel::INFO],
    [404, LogLevel::WARNING],
    [422, LogLevel::WARNING],
    [503, LogLevel::ERROR],
]);

it('truncates oversized bodies after redacting them', function (): void {
    SaloonLogger::setDefault(new SaloonLogger($this->logger, new LoggingOptions(maxBodyBytes: 40, throwOnError: true)));

    connector(MockResponse::make(['password' => 'hunter2', 'data' => str_repeat('x', 200)]))->send(new GetBookingRequest);

    $body = $this->logger->record(1)['context']['http']['body'];

    expect($body)->toStartWith('{"password":"[REDACTED]","data":"xxxx')
        ->toContain('... [truncated, 40 B of')
        ->not->toContain('hunter2');
});

it('omits bodies larger than the parse limit', function (): void {
    SaloonLogger::setDefault(new SaloonLogger($this->logger, new LoggingOptions(maxParseBytes: 10, throwOnError: true)));

    connector(MockResponse::make(str_repeat('a', 50), 200, ['Content-Type' => 'text/plain']))->send(new GetBookingRequest);

    expect($this->logger->record(1)['context']['http']['body'])
        ->toBe('[body omitted: 50 B larger than the 10 B parse limit, text/plain]');
});

it('logs fatal connection errors with the underlying exception', function (): void {
    $connector = connector(MockResponse::make()->throw(
        fn (PendingRequest $pendingRequest) => new FatalRequestException(new RuntimeException('cURL error 28: timed out'), $pendingRequest),
    ));

    expect(fn () => $connector->send(new GetBookingRequest))->toThrow(FatalRequestException::class);

    $failure = $this->logger->record(1);

    expect($failure['level'])->toBe(LogLevel::ERROR)
        ->and($failure['message'])->toBe('saloon-failure')
        ->and($failure['context']['http'])->toBe(['method' => 'GET', 'url' => 'https://api.supplier.test/v1/bookings/42'])
        ->and($failure['context']['error'])->toBe(['type' => RuntimeException::class, 'message' => 'cURL error 28: timed out', 'code' => 0])
        ->and($failure['context']['exception'])->toBeInstanceOf(RuntimeException::class)
        ->and($failure['context']['correlation_id'])->toBe($this->logger->record(0)['context']['correlation_id']);
});

it('logs every retry attempt with its own correlation id', function (): void {
    $connector = connector(MockResponse::make([], 500), MockResponse::make([], 200));
    $request = new GetBookingRequest;
    $request->tries = 2;

    $connector->send($request);

    expect($this->logger->records)->toHaveCount(4)
        ->and($this->logger->record(1)['context']['http']['status'])->toBe(500)
        ->and($this->logger->record(3)['context']['http']['status'])->toBe(200)
        ->and($this->logger->record(0)['context']['correlation_id'])
        ->not->toBe($this->logger->record(2)['context']['correlation_id']);
});

it('logs asynchronous and pooled requests', function (): void {
    $connector = connector(MockResponse::make(['a' => 1]), MockResponse::make(['b' => 2]));

    $connector->pool([new GetBookingRequest, new GetBookingRequest], concurrency: 2)->send()->wait();

    expect($this->logger->records)->toHaveCount(4);
});

it('logs once when both the connector and the request use the plugin', function (): void {
    connector()->send(new LoggedRequest);

    expect($this->logger->records)->toHaveCount(2);
});

it('lets a request customise messages, redaction, logger and bodies', function (): void {
    $other = new ArrayLogger;

    connector(MockResponse::make(['holder' => 'Jane', 'ok' => true]))->send(new LoggedRequest(
        fn (LoggingOptions $options) => $options
            ->withLogger($other)
            ->withMessages(request: 'checkout-to-{supplier}', response: '{supplier}-to-checkout {status}')
            ->redactKeys('holder')
            ->withoutRequestBody(),
    ));

    expect($this->logger->records)->toBeEmpty()
        ->and($other->record(0)['message'])->toBe('checkout-to-ratehawk')
        ->and($other->record(0)['context']['http'])->not->toHaveKey('body')
        ->and($other->record(1)['message'])->toBe('ratehawk-to-checkout 200')
        ->and($other->record(1)['context']['http']['body'])->toBe(['holder' => '[REDACTED]', 'ok' => true]);
});

it('can be disabled per request', function (): void {
    connector()->send(new LoggedRequest(fn (LoggingOptions $options) => $options->disable()));

    expect($this->logger->records)->toBeEmpty();
});

it('never breaks the HTTP call when logging fails', function (): void {
    SaloonLogger::setDefault(new SaloonLogger($this->logger));

    $response = connector()->send(new LoggedRequest(fn () => throw new RuntimeException('bad config')));

    expect($response->status())->toBe(200)
        ->and($this->logger->record(0)['message'])->toBe('saloon-logger failed to write a log entry')
        ->and($this->logger->record(0)['context']['exception']->getMessage())->toBe('bad config');
});

it('throws a helpful exception when no logger is configured', function (): void {
    SaloonLogger::setDefault(null);

    connector()->send(new GetBookingRequest);
})->throws(MissingLoggerException::class);

it('strips credentials embedded in the URL', function (): void {
    $connector = new class extends Connector
    {
        use HasLogging;

        public function resolveBaseUrl(): string
        {
            return 'https://user:pass@api.supplier.test';
        }
    };

    $connector->withMockClient(new MockClient([MockResponse::make()]))->send(new GetBookingRequest);

    expect($this->logger->record(0)['context']['http']['url'])->toBe('https://api.supplier.test/bookings/42');
});
