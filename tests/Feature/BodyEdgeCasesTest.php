<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\Tests\Support\CustomBodyRequest;
use Milzer\SaloonLogger\Tests\Support\GetBookingRequest;
use Milzer\SaloonLogger\Tests\Support\MultipartFilesRequest;
use Milzer\SaloonLogger\Tests\Support\SearchRequest;
use Milzer\SaloonLogger\Tests\Support\StreamRequest;
use Saloon\Http\Faking\MockResponse;

/**
 * @return resource
 */
function tempResource(string $contents)
{
    $resource = fopen('php://temp', 'r+');

    if ($resource === false) {
        throw new RuntimeException('Unable to open php://temp.');
    }

    fwrite($resource, $contents);

    return $resource;
}

it('reads seekable resource bodies and restores their position', function (): void {
    $resource = tempResource('{"a":1}');
    fseek($resource, 2);

    $request = new StreamRequest($resource);
    $request->headers()->add('Content-Type', 'application/json');

    connector()->send($request);

    expect(testLog()->record(0)->get('http.body'))->toBe(['a' => 1])
        ->and(ftell($resource))->toBe(2);
});

it('logs an empty resource body as null', function (): void {
    connector()->send(new StreamRequest(tempResource('')));

    expect(testLog()->record(0)->get('http.body'))->toBeNull();
});

it('never reads non-seekable resources', function (): void {
    $pipe = popen('echo secret', 'r');

    connector()->send(new StreamRequest($pipe));

    expect(testLog()->record(0)->get('http.body'))->toBe('[body omitted: non-seekable stream]');

    if (is_resource($pipe)) {
        pclose($pipe);
    }
});

it('omits resource bodies larger than the parse limit', function (): void {
    useOptions(new LoggingOptions(maxParseBytes: 5, throwOnError: true));

    connector()->send(new StreamRequest(tempResource(str_repeat('a', 20))));

    expect(testLog()->record(0)->get('http.body'))->toBe('[body omitted: stream larger than the 5 B parse limit]');
});

it('omits streams of unknown size once they exceed the parse limit', function (): void {
    useOptions(new LoggingOptions(maxParseBytes: 5, throwOnError: true));

    $stream = FnStream::decorate(Utils::streamFor(str_repeat('a', 20)), ['getSize' => fn (): ?int => null]);

    connector()->send(new StreamRequest($stream));

    expect(testLog()->record(0)->get('http.body'))->toBe('[body omitted: stream larger than the 5 B parse limit]');
});

it('omits string bodies larger than the parse limit', function (): void {
    useOptions(new LoggingOptions(maxParseBytes: 10, throwOnError: true));

    connector()->send(new SearchRequest);

    expect(testLog()->record(0)->get('http.body'))->toStartWith('[body omitted: ')
        ->toEndWith('larger than the 10 B parse limit, application/json]');
});

it('describes body repositories it cannot stringify', function (): void {
    connector()->send(new CustomBodyRequest);

    expect(testLog()->record(0)->get('http.body'))
        ->toStartWith('[body omitted: unsupported body repository ');
});

it('describes multipart stream and resource files by size', function (): void {
    connector()->send(new MultipartFilesRequest(Utils::streamFor('12345'), tempResource('1234567890')));

    expect(testLog()->record(0)->get('http.body'))->toBe([
        ['name' => 'stream', 'filename' => 'a.bin', 'contents' => '[file omitted: 5 B]'],
        ['name' => 'resource', 'filename' => 'b.bin', 'contents' => '[file omitted: 10 B]'],
    ]);
});

it('never truncates when the body limit is disabled', function (): void {
    useOptions((new LoggingOptions(throwOnError: true))->withMaxBodyBytes(null));

    $data = str_repeat('x', 200 * 1024);

    connector(MockResponse::make(['data' => $data]))->send(new GetBookingRequest);

    expect(testLog()->record(1)->get('http.body'))->toBe(['data' => $data]);
});

it('keeps scalar JSON bodies as they are', function (): void {
    connector(MockResponse::make('123', 200, ['Content-Type' => 'application/json']))->send(new GetBookingRequest);

    expect(testLog()->record(1)->get('http.body'))->toBe(123);
});
