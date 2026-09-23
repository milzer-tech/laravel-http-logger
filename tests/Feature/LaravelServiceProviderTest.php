<?php

declare(strict_types=1);

use Illuminate\Log\LogManager;
use Milzer\SaloonLogger\Laravel\SaloonLoggerServiceProvider;
use Milzer\SaloonLogger\SaloonLogger;
use Milzer\SaloonLogger\Serialization\Formatters\TextFormatter;
use Milzer\SaloonLogger\Tests\Support\GetBookingRequest;
use Milzer\SaloonLogger\Tests\Support\TestConnector;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Orchestra\Testbench\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

beforeEach(function (): void {
    app()->register(SaloonLoggerServiceProvider::class);
});

it('registers the logger from config and makes it the plugin default', function (): void {
    $handler = new TestHandler;

    config([
        'logging.channels.saloon-test' => ['driver' => 'custom', 'via' => fn (): Logger => new Logger('test', [$handler])],
        'saloon-logger.channel' => 'saloon-test',
        'saloon-logger.messages.request' => 'checkout-to-{supplier}',
        'saloon-logger.context' => ['environment' => 'testing'],
    ]);

    expect(SaloonLogger::resolve())->toBe(app(SaloonLogger::class));

    (new TestConnector)->withMockClient(new MockClient([MockResponse::make()]))->send(new GetBookingRequest);

    [$request, $response] = $handler->getRecords();

    expect($request->level->getName())->toBe('INFO')
        ->and($request->message)->toBe('checkout-to-ratehawk')
        ->and($request->context['environment'])->toBe('testing')
        ->and($request->context['project'])->toBe('checkout')
        ->and($response->message)->toBe('saloon-response');
});

it('uses the default channel when none is configured', function (): void {
    config(['saloon-logger.channel' => null]);

    expect(app(SaloonLogger::class)->logger())->toBe(app(LogManager::class)->driver());
});

it('resolves custom body formatters from the container', function (): void {
    config(['saloon-logger.body_formatters' => [TextFormatter::class]]);

    expect(app(SaloonLogger::class)->options()->bodyFormatters)->toHaveCount(1);
});

it('publishes its config', function (): void {
    expect(config('saloon-logger.limits.max_body_bytes'))->toBe(131072)
        ->and(SaloonLoggerServiceProvider::pathsToPublish(SaloonLoggerServiceProvider::class, 'saloon-logger-config'))
        ->not->toBeEmpty();
});

it('uses the default message names unless they are overridden', function (): void {
    $options = app(SaloonLogger::class)->options();

    expect($options->requestMessage)->toBe('saloon-request')
        ->and($options->responseMessage)->toBe('saloon-response')
        ->and($options->failureMessage)->toBe('saloon-failure');
});
