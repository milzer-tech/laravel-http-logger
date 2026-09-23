<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Tests\Support;

use Milzer\SaloonLogger\Contracts\ProvidesLogContext;
use Milzer\SaloonLogger\Plugins\HasLogging;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

class TestConnector extends Connector implements ProvidesLogContext
{
    use HasLogging;

    public function resolveBaseUrl(): string
    {
        return 'https://api.supplier.test/v1';
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator('super-secret-token');
    }

    public function logContext(PendingRequest $pendingRequest): array
    {
        return ['project' => 'checkout', 'supplier' => 'ratehawk', 'api' => 'accommodations'];
    }
}
