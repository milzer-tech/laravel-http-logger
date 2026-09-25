<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Laravel\HttpLoggerServiceProvider;
use Milzer\HttpLogger\Tests\Support\LogRecord;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    app()->register(HttpLoggerServiceProvider::class);
    app()->instance(HttpLogger::class, new HttpLogger(testLog(), new LoggingOptions(throwOnError: true)));
});

it('logs calls made with the Http facade, including faked responses', function (): void {
    Http::fake(['api.ratehawk.test/*' => Http::response(['booking_id' => 'RH-1', 'token' => 'x'], 201)]);

    Http::withMiddleware(HttpLogger::middleware(['supplier' => 'ratehawk']))
        ->withToken('secret')
        ->withOptions(['log_context' => ['action' => 'book']])
        ->post('https://api.ratehawk.test/v1/bookings', ['hotel' => 'H1', 'cvv' => '123'])
        ->throw();

    [$request, $response] = testLog()->records;

    expect($request->message)->toBe('outgoing-request')
        ->and($request->context)->toMatchArray(['supplier' => 'ratehawk', 'action' => 'book'])
        ->and($request->get('http.headers.Authorization'))->toBe('[REDACTED]')
        ->and($request->get('http.body'))->toBe(['hotel' => 'H1', 'cvv' => '[REDACTED]'])
        ->and($response->get('http.status'))->toBe(201)
        ->and($response->get('http.body'))->toBe(['booking_id' => 'RH-1', 'token' => '[REDACTED]']);
});

it('logs every call once when registered globally', function (): void {
    Http::globalMiddleware(HttpLogger::middleware());
    Http::fake();

    Http::get('https://api.one.test');
    Http::withMiddleware(HttpLogger::middleware())->get('https://api.two.test');

    expect(array_map(fn (LogRecord $record): string => $record->string('http.url'), testLog()->records))->toBe([
        'https://api.one.test', 'https://api.one.test',
        'https://api.two.test', 'https://api.two.test',
    ]);
});

it('logs pooled requests', function (): void {
    Http::fake();

    Http::pool(fn (Pool $pool): array => [
        $pool->withMiddleware(HttpLogger::middleware())->get('https://api.one.test'),
        $pool->withMiddleware(HttpLogger::middleware())->get('https://api.two.test'),
    ]);

    expect(testLog()->records)->toHaveCount(4);
});

it('logs every retry attempt', function (): void {
    Http::fake(['*' => Http::sequence()->push('busy', 503)->push('ok', 200)]);

    Http::withMiddleware(HttpLogger::middleware())->retry(2, 0, throw: false)->get('https://api.one.test');

    expect(array_map(fn (LogRecord $record): mixed => $record->has('http.status') ? $record->get('http.status') : null, testLog()->records))
        ->toBe([null, 503, null, 200]);
});

it('logs real connection failures', function (): void {
    expect(fn () => Http::withMiddleware(HttpLogger::middleware())->connectTimeout(1)->get('http://127.0.0.1:1/?api_key=sk_live_1'))
        ->toThrow(ConnectionException::class);

    $failure = testLog()->record(1);

    expect($failure->message)->toBe('outgoing-failure')
        ->and($failure->string('error.message'))->not->toContain('sk_live_1');
});

it('shares the trace id with the incoming request', function (): void {
    Http::fake();

    Route::middleware('http-logger')->get('/checkout', function (): string {
        Http::withMiddleware(HttpLogger::middleware())->get('https://api.one.test');

        return 'ok';
    });

    laravel()->get('/checkout', ['X-Request-Id' => 'trace-7'])->assertOk();

    expect(array_map(fn (LogRecord $record): string => $record->string('direction').':'.$record->string('trace_id'), testLog()->records))->toBe([
        'incoming:trace-7', 'outgoing:trace-7', 'outgoing:trace-7', 'incoming:trace-7',
    ]);
});
