<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Laravel\DeferredWriter;
use Milzer\HttpLogger\Laravel\HttpLoggerServiceProvider;
use Milzer\HttpLogger\Tests\Support\GetBookingRequest;
use Milzer\HttpLogger\Tests\Support\LogRecord;
use Orchestra\Testbench\TestCase;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('writes immediately in the console', function (): void {
    $written = false;

    (new DeferredWriter(app()))->write(function () use (&$written): void {
        $written = true;
    });

    expect($written)->toBeTrue();
});

it('writes everything after the response, in the order it happened', function (): void {
    /** @var list<Closure> $deferred */
    $deferred = [];

    $app = Mockery::mock(Application::class);
    $app->shouldReceive('runningInConsole')->andReturn(false);
    $app->shouldReceive('terminating')->andReturnUsing(function (Closure $callback) use (&$deferred, $app): Application {
        $deferred[] = $callback;

        return $app;
    });

    app()->register(HttpLoggerServiceProvider::class);
    app()->instance(HttpLogger::class, new HttpLogger(testLog(), new LoggingOptions(throwOnError: true), new DeferredWriter($app)));

    $loggedDuringRequest = null;

    Route::middleware('http-logger')->get('/supplier', function () use (&$loggedDuringRequest): string {
        connector(MockResponse::make(['ok' => true]))->send(new GetBookingRequest);
        $loggedDuringRequest = count(testLog()->records);

        return 'done';
    });

    laravel()->get('/supplier')->assertOk();

    expect($loggedDuringRequest)->toBe(0)
        ->and(testLog()->records)->toBeEmpty()
        ->and($deferred)->toHaveCount(4);

    foreach ($deferred as $callback) {
        $callback();
    }

    expect(array_map(fn (LogRecord $record): string => $record->message, testLog()->records))->toBe([
        'incoming-request',
        'outgoing-request',
        'outgoing-response',
        'incoming-response',
    ]);
});
