<?php

declare(strict_types=1);

use Milzer\HttpLogger\Core\Direction;
use Milzer\HttpLogger\Core\HttpLogger;
use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Core\Serialization\HttpFields;
use Milzer\HttpLogger\Core\Support\MimeType;
use Milzer\HttpLogger\Core\Writers\ImmediateWriter;
use Milzer\HttpLogger\Tests\Support\ArrayLogger;
use Milzer\HttpLogger\Tests\Support\FailingBodyStore;

afterEach(function (): void {
    HttpLogger::resolveTraceIdUsing(null);
});

it('returns new instances when changing the writer or store', function (): void {
    $logger = new HttpLogger(new ArrayLogger);
    $writer = new ImmediateWriter;
    $store = new FailingBodyStore;

    expect($logger->withWriter($writer)->writer())->toBe($writer)
        ->and($logger->withStore($store)->store())->toBe($store)
        ->and($logger->store())->toBeNull();
});

it('only accepts non-empty string trace ids', function (mixed $resolved, ?string $expected): void {
    HttpLogger::resolveTraceIdUsing(fn (): mixed => $resolved);

    expect(HttpLogger::traceId())->toBe($expected);
})->with([
    ['abc', 'abc'],
    ['', null],
    [123, null],
    [null, null],
]);

it('lets an explicit trace id win over the resolver', function (): void {
    $logs = new ArrayLogger;
    HttpLogger::resolveTraceIdUsing(fn (): string => 'from-resolver');

    $exchange = (new HttpLogger($logs))->exchange(Direction::Incoming, traceId: 'explicit');
    $exchange->request(fn (): array => ['http' => ['method' => 'GET']]);

    expect($logs->record(0)->get('trace_id'))->toBe('explicit');
});

it('freezes the duration when an exchange is stopped', function (): void {
    $logs = new ArrayLogger;
    $exchange = (new HttpLogger($logs))->exchange(Direction::Incoming);

    $exchange->start();
    $exchange->stop();
    usleep(20_000);
    $exchange->response(200, fn (): array => ['http' => ['route' => 'bookings.store']]);

    expect($logs->record(0)->get('response_time_in_seconds'))->toBeLessThan(0.02);
});

it('resolves the route placeholder in incoming messages', function (): void {
    $logs = new ArrayLogger;
    $options = (new LoggingOptions)->withIncomingMessages(response: '{route} answered {status}');

    (new HttpLogger($logs, $options))->exchange(Direction::Incoming)
        ->response(201, fn (): array => ['http' => ['route' => 'bookings.store', 'status' => 201]]);

    expect($logs->record(0)->message)->toBe('bookings.store answered 201');
});

it('picks file extensions for stored bodies', function (string $mimeType, bool $structured, string $expected): void {
    expect(MimeType::extension($mimeType, $structured))->toBe($expected);
})->with([
    ['text/plain', true, 'json'],
    ['application/problem+json', false, 'json'],
    ['application/soap+xml', false, 'xml'],
    ['text/html', false, 'html'],
    ['text/csv', false, 'txt'],
]);

it('reads the first value of multi-value headers', function (): void {
    $headers = HttpFields::headers(['Accept' => ['text/html', 'application/json'], 'X-Empty' => []]);

    expect(HttpFields::header($headers, 'accept'))->toBe('text/html')
        ->and(HttpFields::header($headers, 'x-empty'))->toBeNull()
        ->and(HttpFields::header($headers, 'missing'))->toBeNull();
});
