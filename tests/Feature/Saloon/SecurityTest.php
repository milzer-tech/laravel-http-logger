<?php

declare(strict_types=1);

use Milzer\HttpLogger\Core\LoggingOptions;
use Milzer\HttpLogger\Tests\Support\GetBookingRequest;
use Milzer\HttpLogger\Tests\Support\LoggedRequest;
use Milzer\HttpLogger\Tests\Support\NestedMultipartRequest;
use Milzer\HttpLogger\Tests\Support\NestedXmlRequest;
use Milzer\HttpLogger\Tests\Support\TestConnector;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;

function failingConnector(string $message): TestConnector
{
    return connector(MockResponse::make()->throw(
        fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(
            new RuntimeException($message, 28, new LogicException('previous with token=abc123')),
            $pendingRequest,
        ),
    ));
}

it('masks secrets in failure messages, like the URL Guzzle appends', function (): void {
    $connector = failingConnector('cURL error 28: timed out for https://bob:pw@api.supplier.test/v1/search?lang=de&api_key=sk_live_123');

    expect(fn (): Response => $connector->send(new GetBookingRequest))->toThrow(FatalRequestException::class);

    $failure = testLog()->record(1);

    expect($failure->get('error.message'))
        ->toBe('cURL error 28: timed out for https://[REDACTED]@api.supplier.test/v1/search?lang=de&api_key=%5BREDACTED%5D')
        ->and($failure->get('error.previous'))->toBe([
            ['type' => LogicException::class, 'message' => 'previous with token=%5BREDACTED%5D', 'code' => 0],
        ])
        ->and($failure->has('exception'))->toBeFalse();

    $entry = (string) json_encode($failure->context);

    expect($entry)->not->toContain('sk_live_123');
    expect($entry)->not->toContain('abc123');
});

it('passes the raw exception object only when explicitly enabled', function (): void {
    useOptions(new LoggingOptions(logExceptionObject: true, throwOnError: true));

    $connector = failingConnector('timed out');

    expect(fn (): Response => $connector->send(new GetBookingRequest))->toThrow(FatalRequestException::class);

    expect(testLog()->record(1)->get('exception'))->toBeInstanceOf(RuntimeException::class);
});

it('masks secrets in the error entry of the logger itself', function (): void {
    useOptions(new LoggingOptions);

    connector()->send(new LoggedRequest(fn (): LoggingOptions => throw new RuntimeException('bad config for ?client_secret=shh')));

    expect(testLog()->record(0)->get('error.message'))->toBe('bad config for ?client_secret=%5BREDACTED%5D')
        ->and(testLog()->record(0)->has('exception'))->toBeFalse();
});

it('masks nested multipart field names', function (): void {
    connector()->send(new NestedMultipartRequest);

    expect(testLog()->record(0)->get('http.body'))->toBe([
        ['name' => 'payment[holder]', 'contents' => 'Jane Doe'],
        ['name' => 'payment[card_number]', 'contents' => '[REDACTED]'],
    ]);
});

it('masks sensitive XML elements that contain child elements', function (): void {
    connector()->send(new NestedXmlRequest);

    expect(testLog()->record(0)->get('http.body'))
        ->toBe('<Envelope><password>[REDACTED]</password><Hotel>H1</Hotel></Envelope>');
});
