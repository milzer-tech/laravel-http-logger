<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Milzer\SaloonLogger\Laravel\SaloonLoggerServiceProvider;
use Milzer\SaloonLogger\SaloonLogger;
use Milzer\SaloonLogger\Tests\Support\GetBookingRequest;
use Milzer\SaloonLogger\Tests\Support\TestConnector;
use Orchestra\Testbench\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

beforeEach(function (): void {
    $this->app->register(SaloonLoggerServiceProvider::class);
});

it('registers the logger from config and makes it the plugin default', function (): void {
    config([
        'saloon-logger.channel' => 'stack',
        'saloon-logger.messages.request' => 'checkout-to-{supplier}',
        'saloon-logger.context' => ['environment' => 'testing'],
    ]);

    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('log')->once()->withArgs(
        fn (string $level, string $message, array $context) => $level === 'info'
            && $message === 'checkout-to-ratehawk'
            && $context['environment'] === 'testing'
            && $context['project'] === 'checkout',
    );
    Log::shouldReceive('log')->once()->withArgs(fn (string $level, string $message) => $message === 'saloon-response');

    expect(SaloonLogger::resolve())->toBe($this->app->make(SaloonLogger::class));

    (new TestConnector)->withMockClient(new MockClient([MockResponse::make()]))->send(new GetBookingRequest);
});

it('publishes its config', function (): void {
    expect(config('saloon-logger.limits.max_body_bytes'))->toBe(131072)
        ->and(SaloonLoggerServiceProvider::pathsToPublish(SaloonLoggerServiceProvider::class, 'saloon-logger-config'))
        ->not->toBeEmpty();
});

it('uses the default message names unless they are overridden', function (): void {
    $options = $this->app->make(SaloonLogger::class)->options();

    expect($options->requestMessage)->toBe('saloon-request')
        ->and($options->responseMessage)->toBe('saloon-response')
        ->and($options->failureMessage)->toBe('saloon-failure');
});
