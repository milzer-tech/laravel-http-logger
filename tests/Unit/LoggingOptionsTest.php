<?php

declare(strict_types=1);

use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\Serialization\Formatters\TextFormatter;
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
        'messages' => ['request' => 'checkout-to-{supplier}'],
        'levels' => ['client_error' => 'notice'],
        'log' => ['headers' => false],
        'redact' => ['keys' => ['foo']],
        'limits' => ['max_body_bytes' => null],
        'context' => ['project' => 'checkout'],
        'body_formatters' => [TextFormatter::class],
    ]);

    expect($options->enabled)->toBeFalse()
        ->and($options->requestMessage)->toBe('checkout-to-{supplier}')
        ->and($options->responseMessage)->toBe('saloon-response')
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
