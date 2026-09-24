<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use Milzer\HttpLogger\Saloon\HasLogging;
use Milzer\HttpLogger\Tests\Support\GetBookingRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;

it('logs real connection failures through the Guzzle sender', function (): void {
    $connector = new class extends Connector
    {
        use HasLogging;

        public function resolveBaseUrl(): string
        {
            return 'http://127.0.0.1:1';
        }

        protected function defaultConfig(): array
        {
            return ['connect_timeout' => 1];
        }
    };

    expect(fn (): Response => $connector->send(new GetBookingRequest))->toThrow(FatalRequestException::class);

    expect(testLog()->records)->toHaveCount(2)
        ->and(testLog()->record(1)->message)->toBe('outgoing-failure')
        ->and(testLog()->record(1)->get('error.type'))->toBe(ConnectException::class);
});
