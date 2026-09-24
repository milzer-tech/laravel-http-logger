<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Writers;

use Closure;
use Milzer\HttpLogger\Core\Contracts\Writer;

final readonly class ImmediateWriter implements Writer
{
    public function write(Closure $callback): void
    {
        $callback();
    }
}
