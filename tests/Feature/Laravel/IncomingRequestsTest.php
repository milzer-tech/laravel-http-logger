<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Laravel\HttpLoggerServiceProvider;
use Milzer\HttpLogger\Tests\Support\GetBookingRequest;
use Milzer\HttpLogger\Tests\Support\IncomingContext;
use Milzer\HttpLogger\Tests\Support\LogRecord;
use Orchestra\Testbench\TestCase;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(TestCase::class);

beforeEach(function (): void {
    app()->register(HttpLoggerServiceProvider::class);
    app()->instance(HttpLogger::class, new HttpLogger(testLog(), new LoggingOptions(throwOnError: true)));

    Route::middleware('http-logger')->group(function (): void {
        Route::post('/bookings', fn (): array => ['booking_id' => 'B-1', 'access_token' => 'tok'])->name('bookings.store');
        Route::match(['GET', 'PUT'], '/search', fn (): string => 'ok');
        Route::get('/fail', fn (): never => abort(503));
        Route::get('/missing', fn (): never => abort(404));
        Route::get('/stream', fn (): StreamedResponse => response()->stream(function (): void {
            echo 'chunk';
        }));
        Route::get('/download', fn (): BinaryFileResponse => response()->download(__FILE__));
        Route::post('/upload', fn (): string => 'uploaded');
        Route::get('/up', fn (): string => 'healthy');
        Route::get('/supplier', function (): string {
            connector(MockResponse::make(['ok' => true]))->send(new GetBookingRequest);

            return 'done';
        });
    });
});

it('logs incoming requests and responses as correlated entries', function (): void {
    laravel()->postJson('/bookings?lang=de&api_key=secret', ['hotel' => 'H1', 'password' => 'hunter2'], ['Authorization' => 'Bearer x'])
        ->assertOk();

    expect(testLog()->records)->toHaveCount(2);

    $request = testLog()->record(0);
    $response = testLog()->record(1);

    expect($request->message)->toBe('incoming-request')
        ->and($request->get('direction'))->toBe('incoming')
        ->and($request->get('http.method'))->toBe('POST')
        ->and($request->get('http.url'))->toBe('http://localhost/bookings')
        ->and($request->get('http.route'))->toBe('bookings.store')
        ->and($request->get('http.ip'))->toBe('127.0.0.1')
        ->and($request->get('http.queries'))->toBe(['lang' => 'de', 'api_key' => '[REDACTED]'])
        ->and($request->get('http.headers.authorization'))->toBe('[REDACTED]')
        ->and($request->get('http.body'))->toBe(['hotel' => 'H1', 'password' => '[REDACTED]'])
        ->and($request->get('trace_id'))->toMatch('/^[a-f0-9]{32}$/')
        ->and($request->get('occurred_at'))->toBeString();

    expect($response->message)->toBe('incoming-response')
        ->and($response->level)->toBe('info')
        ->and($response->get('http.status'))->toBe(200)
        ->and($response->get('http.body'))->toBe(['booking_id' => 'B-1', 'access_token' => '[REDACTED]'])
        ->and($response->get('response_time_in_seconds'))->toBeFloat()
        ->and($response->get('correlation_id'))->toBe($request->get('correlation_id'))
        ->and($response->get('trace_id'))->toBe($request->get('trace_id'));
});

it('uses the route uri when the route has no name', function (): void {
    laravel()->get('/search')->assertOk();

    expect(testLog()->record(0)->get('http.route'))->toBe('search')
        ->and(testLog()->record(1)->get('http.body'))->toBe('ok');
});

it('uses warning and error levels for failed responses', function (string $uri, string $level): void {
    laravel()->get($uri);

    expect(testLog()->record(1)->level)->toBe($level);
})->with([
    ['/missing', 'warning'],
    ['/fail', 'error'],
]);

it('reuses a safe trace id sent by the caller', function (): void {
    laravel()->get('/search', ['X-Request-Id' => 'gateway-123'])->assertOk();

    expect(testLog()->record(0)->get('trace_id'))->toBe('gateway-123');
});

it('ignores unsafe trace ids', function (): void {
    laravel()->get('/search', ['X-Request-Id' => "bad id\n"])->assertOk();

    expect(testLog()->record(0)->get('trace_id'))->toMatch('/^[a-f0-9]{32}$/');
});

it('shares the trace id with outgoing Saloon calls', function (): void {
    laravel()->get('/supplier', ['X-Request-Id' => 'trace-42'])->assertOk();

    $directions = array_map(fn (LogRecord $record): mixed => $record->get('direction'), testLog()->records);

    expect($directions)->toBe(['incoming', 'outgoing', 'outgoing', 'incoming'])
        ->and(array_map(fn (LogRecord $record): mixed => $record->get('trace_id'), testLog()->records))
        ->toBe(['trace-42', 'trace-42', 'trace-42', 'trace-42']);
});

it('skips excluded paths', function (): void {
    laravel()->get('/up')->assertOk();

    expect(testLog()->records)->toBeEmpty();
});

it('summarises multipart uploads', function (): void {
    laravel()->post('/upload', [
        'title' => 'Voucher',
        'password' => 'secret',
        'meta' => ['lang' => 'de'],
        'file' => UploadedFile::fake()->create('voucher.pdf', 2),
        'attachments' => [UploadedFile::fake()->create('invoice.pdf', 1)],
    ])->assertOk();

    expect(testLog()->record(0)->get('http.body'))->toBe([
        ['name' => 'title', 'contents' => 'Voucher'],
        ['name' => 'password', 'contents' => '[REDACTED]'],
        ['name' => 'meta.lang', 'contents' => 'de'],
        ['name' => 'file', 'filename' => 'voucher.pdf', 'contents' => '[file omitted: 2 KB]'],
        ['name' => 'attachments.0', 'filename' => 'invoice.pdf', 'contents' => '[file omitted: 1 KB]'],
    ]);
});

it('reads multipart bodies sent with a multipart content type', function (): void {
    laravel()->call('POST', '/upload', ['title' => 'Voucher'], [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'])->assertOk();

    expect(testLog()->record(0)->get('http.body'))->toBe([['name' => 'title', 'contents' => 'Voucher']]);
});

it('masks nested multipart fields', function (): void {
    laravel()->post('/upload', [
        'payment' => ['holder' => 'Jane', 'card_number' => '4111111111111111'],
        'client_secret' => ['value' => 'shh'],
        'file' => UploadedFile::fake()->create('a.pdf', 1),
    ])->assertOk();

    expect(testLog()->record(0)->get('http.body'))->toBe([
        ['name' => 'payment.holder', 'contents' => 'Jane'],
        ['name' => 'payment.card_number', 'contents' => '[REDACTED]'],
        ['name' => 'client_secret', 'contents' => '[REDACTED]'],
        ['name' => 'file', 'filename' => 'a.pdf', 'contents' => '[file omitted: 1 KB]'],
    ]);
});

it('keeps multipart summaries within the body limit', function (): void {
    app()->instance(HttpLogger::class, new HttpLogger(testLog(), new LoggingOptions(maxBodyBytes: 200, throwOnError: true)));

    $fields = [];
    for ($i = 0; $i < 50; $i++) {
        $fields['field'.$i] = str_repeat('x', 20);
    }

    laravel()->post('/upload', $fields)->assertOk();

    $body = testLog()->record(0)->get('http.body');
    $marker = is_array($body) ? array_pop($body) : null;

    expect($marker)->toBeArray()
        ->and(is_array($marker) ? $marker['contents'] : null)->toBe(sprintf('[%d more parts omitted]', 50 - count((array) $body)))
        ->and(strlen((string) json_encode($body)))->toBeLessThanOrEqual(200);
});

it('does not read bodies whose Content-Length exceeds the limit', function (): void {
    app()->instance(HttpLogger::class, new HttpLogger(testLog(), new LoggingOptions(maxParseBytes: 100, throwOnError: true)));

    laravel()->call('PUT', '/search', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '5000000'], 'small');

    expect(testLog()->record(0)->get('http.body'))->toBe('[body omitted: 4.8 MB larger than the 100 B limit, text/plain]');
});

it('reads bodies without Content-Length only up to the limit', function (): void {
    app()->instance(HttpLogger::class, new HttpLogger(testLog(), new LoggingOptions(maxParseBytes: 100, throwOnError: true)));

    laravel()->call('PUT', '/search', [], [], [], ['CONTENT_TYPE' => 'text/plain'], str_repeat('a', 500));

    expect(testLog()->record(0)->get('http.body'))->toBe('[body omitted: stream larger than the 100 B limit, text/plain]');
});

it('describes streamed responses', function (): void {
    laravel()->get('/stream');

    expect(testLog()->record(1)->get('http.body'))->toBe('[body omitted: streamed response]');
});

it('describes file downloads', function (): void {
    laravel()->get('/download');

    expect(testLog()->record(1)->get('http.body'))->toStartWith('[binary body omitted: file download, ');
});

it('adds the authenticated user id when the user is already resolved', function (): void {
    laravel()->actingAs(new GenericUser(['id' => 7]))->get('/search')->assertOk();

    expect(testLog()->record(1)->get('user_id'))->toBe(7);
});

it('adds context from the configured resolver', function (): void {
    config(['http-logger.incoming.context' => IncomingContext::class]);

    laravel()->get('/search', ['X-Client' => 'tui-markets'])->assertOk();

    expect(testLog()->record(0)->get('client'))->toBe('tui-markets')
        ->and(testLog()->record(0)->get('api'))->toBe('accommodations');
});

it('never breaks the request when the context resolver is misconfigured', function (): void {
    app()->instance(HttpLogger::class, new HttpLogger(testLog()));
    config(['http-logger.incoming.context' => stdClass::class]);

    laravel()->get('/search')->assertOk();

    expect(testLog()->record(0)->message)->toBe('http-logger failed to write a log entry');
});

it('uses custom incoming messages', function (): void {
    app()->instance(HttpLogger::class, new HttpLogger(
        testLog(),
        (new LoggingOptions(throwOnError: true))->withIncomingMessages(request: 'nezasa-to-flow', response: 'flow-to-nezasa {status}'),
    ));

    laravel()->get('/search')->assertOk();

    expect(testLog()->record(0)->message)->toBe('nezasa-to-flow')
        ->and(testLog()->record(1)->message)->toBe('flow-to-nezasa 200');
});
