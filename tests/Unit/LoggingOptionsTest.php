<?php

declare(strict_types=1);

use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Core\Serialization\Formatters\TextFormatter;
use Milzer\HttpLogger\Tests\Support\ArrayLogger;
use Psr\Log\LogLevel;

it('is immutable', function (): void {
    $options = new LoggingOptions;
    $changed = $options->withoutBodies()->redactKeys('passport');

    expect($options->logRequestBody)->toBeTrue()
        ->and($changed->logRequestBody)->toBeFalse()
        ->and($changed->logResponseBody)->toBeFalse()
        ->and($changed->redactKeys)->toContain('passport', '*password*');
});

it('builds from a config array', function (): void {
    $options = LoggingOptions::fromArray([
        'enabled' => false,
        'messages' => ['outgoing' => ['request' => 'checkout-to-{supplier}'], 'incoming' => ['response' => 'api-response']],
        'storage' => ['max_bytes' => 1024],
        'levels' => ['client_error' => 'notice'],
        'log' => ['headers' => false],
        'redact' => ['keys' => ['foo']],
        'limits' => ['max_body_bytes' => null],
        'context' => ['project' => 'checkout'],
        'body_formatters' => [TextFormatter::class],
    ]);

    expect($options->enabled)->toBeFalse()
        ->and($options->outgoingRequestMessage)->toBe('checkout-to-{supplier}')
        ->and($options->outgoingResponseMessage)->toBe('outgoing-response')
        ->and($options->incomingRequestMessage)->toBe('incoming-request')
        ->and($options->incomingResponseMessage)->toBe('api-response')
        ->and($options->maxStoredBodyBytes)->toBe(1024)
        ->and($options->clientErrorLevel)->toBe(LogLevel::NOTICE)
        ->and($options->logHeaders)->toBeFalse()
        ->and($options->redactKeys)->toBe(['foo'])
        ->and($options->maxBodyBytes)->toBeNull()
        ->and($options->context)->toBe(['project' => 'checkout'])
        ->and($options->bodyFormatters[0])->toBeInstanceOf(TextFormatter::class);
});

it('rejects invalid log levels', function (): void {
    new LoggingOptions(requestLevel: 'loud');
})->throws(InvalidArgumentException::class);

it('rejects negative size limits', function (int $maxBodyBytes, int $maxParseBytes, int $maxStoredBodyBytes): void {
    new LoggingOptions(maxBodyBytes: $maxBodyBytes, maxParseBytes: $maxParseBytes, maxStoredBodyBytes: $maxStoredBodyBytes);
})->with([
    'body' => [-1, 10, 10],
    'parse' => [10, -1, 10],
    'stored' => [10, 10, -1],
])->throws(InvalidArgumentException::class);

it('rejects body formatters that do not implement the contract', function (): void {
    LoggingOptions::fromArray(['body_formatters' => [stdClass::class]]);
})->throws(InvalidArgumentException::class);

it('offers fluent helpers for every common change', function (): void {
    $logger = new ArrayLogger;
    $formatter = new TextFormatter;

    $options = (new LoggingOptions(context: ['a' => ['b' => 1]]))
        ->withContext(['a' => ['c' => 2], 'd' => 3])
        ->redactHeaders('X-Signature')
        ->withoutHeaders()
        ->withoutResponseBody()
        ->withMaxBodyBytes(10)
        ->withBodyFormatter($formatter)
        ->withLogger($logger);

    expect($options->context)->toBe(['a' => ['b' => 1, 'c' => 2], 'd' => 3])
        ->and($options->redactHeaders)->toContain('X-Signature', 'authorization')
        ->and($options->logHeaders)->toBeFalse()
        ->and($options->logRequestBody)->toBeTrue()
        ->and($options->logResponseBody)->toBeFalse()
        ->and($options->maxBodyBytes)->toBe(10)
        ->and($options->bodyFormatters)->toBe([$formatter])
        ->and($options->logger)->toBe($logger);
});

it('changes messages per direction', function (): void {
    $options = (new LoggingOptions)
        ->withOutgoingMessages(failure: 'supplier-down')
        ->withIncomingMessages(request: 'client-to-app')
        ->withoutBodyStorage();

    expect($options->outgoingRequestMessage)->toBe('outgoing-request')
        ->and($options->outgoingFailureMessage)->toBe('supplier-down')
        ->and($options->incomingRequestMessage)->toBe('client-to-app')
        ->and($options->incomingResponseMessage)->toBe('incoming-response')
        ->and($options->storeLargeBodies)->toBeFalse();
});
