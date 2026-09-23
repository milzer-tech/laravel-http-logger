<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Milzer\SaloonLogger\Laravel\SaloonLoggerServiceProvider;
use Milzer\SaloonLogger\SaloonLogger;
use Milzer\SaloonLogger\Tests\Support\GetBookingRequest;
use Milzer\SaloonLogger\Tests\Support\TestConnector;
use Monolog\Handler\TestHandler;
use Orchestra\Testbench\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

beforeEach(function (): void {
    $this->app->register(SaloonLoggerServiceProvider::class);
});

it('registers the logger from config and makes it the plugin default', function (): void {
    config([
        'logging.channels.saloon-test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'saloon-logger.channel' => 'saloon-test',
        'saloon-logger.messages.request' => 'checkout-to-{supplier}',
        'saloon-logger.context' => ['environment' => 'testing'],
    ]);

    expect(SaloonLogger::resolve())->toBe($this->app->make(SaloonLogger::class));

    (new TestConnector)->withMockClient(new MockClient([MockResponse::make()]))->send(new GetBookingRequest);

    /** @var TestHandler $handler */
    $handler = collect(Log::channel('saloon-test')->getLogger()->getHandlers())
        ->first(fn ($handler) => $handler instanceof TestHandler);

    [$request, $response] = $handler->getRecords();

    expect($request->level->getName())->toBe('INFO')
        ->and($request->message)->toBe('checkout-to-ratehawk')
        ->and($request->context['environment'])->toBe('testing')
        ->and($request->context['project'])->toBe('checkout')
        ->and($response->message)->toBe('saloon-response');
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
