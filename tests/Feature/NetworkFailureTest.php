<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use Milzer\SaloonLogger\Plugins\HasLogging;
use Milzer\SaloonLogger\Tests\Support\GetBookingRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Connector;

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

    expect(fn () => $connector->send(new GetBookingRequest))->toThrow(FatalRequestException::class);

    expect($this->logger->records)->toHaveCount(2)
        ->and($this->logger->record(1)['message'])->toBe('saloon-failure')
        ->and($this->logger->record(1)['context']['error']['type'])->toBe(ConnectException::class);
});
