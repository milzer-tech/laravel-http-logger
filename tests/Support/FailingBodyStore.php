<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Tests\Support;

use DateTimeInterface;
use Milzer\HttpLogger\Core\Contracts\BodyStore;
use Milzer\HttpLogger\Core\Serialization\BodyContext;
use Milzer\HttpLogger\Core\Serialization\StoredBody;
use RuntimeException;

final class FailingBodyStore implements BodyStore
{
    public function store(string $contents, string $extension, BodyContext $context): StoredBody
    {
        throw new RuntimeException('disk full');
    }

    public function prune(DateTimeInterface $before): int
    {
        return 0;
    }
}
