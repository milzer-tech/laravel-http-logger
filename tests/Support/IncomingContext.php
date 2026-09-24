<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Tests\Support;

use Illuminate\Http\Request;
use Milzer\HttpLogger\Laravel\Contracts\ProvidesIncomingLogContext;

final class IncomingContext implements ProvidesIncomingLogContext
{
    public function incomingLogContext(Request $request): array
    {
        return ['client' => $request->headers->get('X-Client') ?? 'unknown', 'api' => 'accommodations'];
    }
}
