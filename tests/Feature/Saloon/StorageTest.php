<?php

declare(strict_types=1);

use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Storage\FilesystemBodyStore;
use Milzer\HttpLogger\Tests\Support\FailingBodyStore;
use Milzer\HttpLogger\Tests\Support\GetBookingRequest;
use Milzer\HttpLogger\Tests\Support\LoggedRequest;
use Milzer\HttpLogger\Tests\Support\SearchRequest;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    HttpLogger::setDefault(new HttpLogger(
        testLog(),
        new LoggingOptions(maxBodyBytes: 60, maxParseBytes: 100, maxStoredBodyBytes: 10_000, throwOnError: true),
        store: new FilesystemBodyStore(temporaryFilesystem(), 'test-disk'),
    ));
});

it('stores the full redacted body and logs a preview with a reference', function (): void {
    connector(MockResponse::make(['token' => 'secret', 'offers' => array_fill(0, 5, ['id' => 'H1'])]))->send(new GetBookingRequest);

    $response = testLog()->record(1);
    $file = $response->get('http.body_file');

    expect($response->get('http.body'))->toBeString()->toContain('[truncated, 60 B of')
        ->and($file)->toBeArray()
        ->and($response->get('http.body_file.disk'))->toBe('test-disk')
        ->and($response->get('http.body_file.path'))
        ->toMatch('#^http-logs/\d{4}/\d{2}/\d{2}/\d{6}-outgoing-'.$response->string('correlation_id').'-response\.json$#');

    $stored = temporaryFilesystem()->read($response->string('http.body_file.path'));

    expect(json_decode($stored, true))->toBe(['token' => '[REDACTED]', 'offers' => array_fill(0, 5, ['id' => 'H1'])])
        ->and($stored)->toContain("\n")
        ->and($response->get('http.body_file.size'))->toBe(strlen($stored));
});

it('stores bodies above the parse limit up to the storage limit', function (): void {
    $xml = '<Hotels>'.str_repeat('<Hotel>Palma</Hotel>', 20).'<Password>p</Password></Hotels>';

    connector(MockResponse::make($xml, 200, ['Content-Type' => 'application/xml']))->send(new GetBookingRequest);

    $path = testLog()->record(1)->string('http.body_file.path');

    expect($path)->toEndWith('-response.xml')
        ->and(temporaryFilesystem()->read($path))->toContain('<Password>[REDACTED]</Password>');
});

it('stores large request bodies too', function (): void {
    connector()->send(new SearchRequest);

    expect(testLog()->record(0)->get('http.body_file.path'))->toEndWith('-request.json');
});

it('keeps the preview when the store fails', function (): void {
    HttpLogger::setDefault(new HttpLogger(testLog(), new LoggingOptions(maxBodyBytes: 20, throwOnError: true), store: new FailingBodyStore));

    connector(MockResponse::make(['data' => str_repeat('x', 100)]))->send(new GetBookingRequest);

    expect(testLog()->record(1)->get('http.body'))->toContain('[truncated')
        ->and(testLog()->record(1)->get('http.body_file'))->toBe(['error' => 'disk full']);
});

it('can skip storage for a single request', function (): void {
    connector(MockResponse::make(['data' => str_repeat('x', 70)]))
        ->send(new LoggedRequest(fn (LoggingOptions $options): LoggingOptions => $options->withoutBodyStorage()));

    expect(testLog()->record(1)->has('http.body_file'))->toBeFalse()
        ->and(testLog()->record(1)->get('http.body'))->toContain('[truncated');
});
