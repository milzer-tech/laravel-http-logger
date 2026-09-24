<?php

declare(strict_types=1);

use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Core\Redaction\Redactor;
use Milzer\HttpLogger\Core\Serialization\Formatters\TextFormatter;
use Milzer\HttpLogger\Core\Support\MimeType;
use Milzer\HttpLogger\Tests\Support\ArrayLogger;

it('formats sizes for humans', function (int $bytes, string $expected): void {
    expect(MimeType::humanSize($bytes))->toBe($expected);
})->with([
    [512, '512 B'],
    [2048, '2 KB'],
    [5 * 1024 * 1024, '5 MB'],
]);

it('extracts the media type essence', function (?string $contentType, string $expected): void {
    expect(MimeType::essence($contentType))->toBe($expected);
})->with([
    [null, ''],
    ['Application/JSON; charset=utf-8', 'application/json'],
]);

it('leaves strings untouched when there are no key rules', function (): void {
    expect((new Redactor([], []))->string('{"password":"x"}'))->toBe('{"password":"x"}');
});

it('returns new instances when changing the logger or options', function (): void {
    $logger = new HttpLogger(new ArrayLogger);
    $otherLogger = new ArrayLogger;
    $otherOptions = new LoggingOptions(logHeaders: false);

    expect($logger->withLogger($otherLogger)->logger())->toBe($otherLogger)
        ->and($logger->withOptions($otherOptions)->options())->toBe($otherOptions)
        ->and($logger->logger())->not->toBe($otherLogger)
        ->and($logger->options())->not->toBe($otherOptions);
});

it('uses the text formatter for anything as a last resort', function (): void {
    $formatter = new TextFormatter;

    expect($formatter->supports('application/x-anything', 'raw'))->toBeTrue()
        ->and($formatter->format('raw', 'application/x-anything'))->toBe('raw');
});
