<?php

declare(strict_types=1);

use Illuminate\Log\LogManager;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\Serialization\Formatters\TextFormatter;
use Milzer\HttpLogger\Laravel\HttpLoggerServiceProvider;
use Milzer\HttpLogger\Tests\Support\GetBookingRequest;
use Milzer\HttpLogger\Tests\Support\TestConnector;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Orchestra\Testbench\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

beforeEach(function (): void {
    app()->register(HttpLoggerServiceProvider::class);
});

it('registers the logger from config and makes it the plugin default', function (): void {
    $handler = new TestHandler;

    config([
        'logging.channels.saloon-test' => ['driver' => 'custom', 'via' => fn (): Logger => new Logger('test', [$handler])],
        'http-logger.channel' => 'saloon-test',
        'http-logger.messages.outgoing.request' => 'checkout-to-{supplier}',
        'http-logger.context' => ['environment' => 'testing'],
    ]);

    expect(HttpLogger::resolve())->toBe(app(HttpLogger::class));

    (new TestConnector)->withMockClient(new MockClient([MockResponse::make()]))->send(new GetBookingRequest);

    [$request, $response] = $handler->getRecords();

    expect($request->level->getName())->toBe('INFO')
        ->and($request->message)->toBe('checkout-to-ratehawk')
        ->and($request->context['environment'])->toBe('testing')
        ->and($request->context['project'])->toBe('checkout')
        ->and($response->message)->toBe('outgoing-response');
});

it('uses the default channel when none is configured', function (): void {
    config(['http-logger.channel' => null]);

    expect(app(HttpLogger::class)->logger())->toBe(app(LogManager::class)->driver());
});

it('resolves custom body formatters from the container', function (): void {
    config(['http-logger.body_formatters' => [TextFormatter::class]]);

    expect(app(HttpLogger::class)->options()->bodyFormatters)->toHaveCount(1);
});

it('publishes its config', function (): void {
    expect(config('http-logger.limits.max_body_bytes'))->toBe(131072)
        ->and(HttpLoggerServiceProvider::pathsToPublish(HttpLoggerServiceProvider::class, 'http-logger-config'))
        ->not->toBeEmpty();
});

it('uses the default message names unless they are overridden', function (): void {
    $options = app(HttpLogger::class)->options();

    expect($options->outgoingRequestMessage)->toBe('outgoing-request')
        ->and($options->outgoingResponseMessage)->toBe('outgoing-response')
        ->and($options->outgoingFailureMessage)->toBe('outgoing-failure')
        ->and($options->incomingRequestMessage)->toBe('incoming-request')
        ->and($options->incomingResponseMessage)->toBe('incoming-response');
});
