<?php

declare(strict_types=1);

use Milzer\SaloonLogger\Contracts\ProvidesLogContext;
use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\Plugins\HasLogging;
use Milzer\SaloonLogger\SaloonLogger;
use Milzer\SaloonLogger\Tests\Support\GetBookingRequest;
use Milzer\SaloonLogger\Tests\Support\LoggedRequest;
use Psr\Log\AbstractLogger;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Connector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;

it('skips failure entries when failures are not logged', function (): void {
    useOptions(new LoggingOptions(logFailures: false, throwOnError: true));

    $connector = connector(MockResponse::make()->throw(
        fn (PendingRequest $pendingRequest): FatalRequestException => new FatalRequestException(new RuntimeException('timeout'), $pendingRequest),
    ));

    expect(fn (): Response => $connector->send(new GetBookingRequest))->toThrow(FatalRequestException::class);
    expect(testLog()->records)->toHaveCount(1)
        ->and(testLog()->record(0)->message)->toBe('saloon-request');
});

it('merges nested context from the connector and the request', function (): void {
    $connector = new class extends Connector implements ProvidesLogContext
    {
        use HasLogging;

        public function resolveBaseUrl(): string
        {
            return 'https://api.supplier.test';
        }

        public function logContext(PendingRequest $pendingRequest): array
        {
            return ['booking' => ['supplier' => 'ratehawk', 'market' => 'de']];
        }
    };

    $request = new class extends Request implements ProvidesLogContext
    {
        protected Method $method = Method::GET;

        public function resolveEndpoint(): string
        {
            return '/';
        }

        public function logContext(PendingRequest $pendingRequest): array
        {
            return ['booking' => ['market' => 'at', 'reference' => 'BK-1']];
        }
    };

    $connector->withMockClient(new MockClient([MockResponse::make()]))->send($request);

    expect(testLog()->record(0)->get('booking'))->toBe(['supplier' => 'ratehawk', 'market' => 'at', 'reference' => 'BK-1']);
});

it('rethrows logger errors when throwOnError is enabled', function (): void {
    expect(fn (): Response => connector()->send(new LoggedRequest(fn (): LoggingOptions => throw new RuntimeException('bad config'))))
        ->toThrow(RuntimeException::class, 'bad config');
});

it('falls back to error_log when the logger itself fails', function (): void {
    $broken = new class extends AbstractLogger
    {
        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            throw new RuntimeException('log backend down');
        }
    };

    $errorLog = tempnam(sys_get_temp_dir(), 'saloon-logger');
    $previous = ini_set('error_log', (string) $errorLog);

    try {
        SaloonLogger::setDefault(new SaloonLogger($broken));

        $response = connector()->send(new GetBookingRequest);

        expect($response->status())->toBe(200)
            ->and((string) file_get_contents((string) $errorLog))->toContain('[saloon-logger] RuntimeException: log backend down');
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
        @unlink((string) $errorLog);
    }
});
