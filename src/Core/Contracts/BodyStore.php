<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Contracts;

use DateTimeInterface;
use Milzer\HttpLogger\Core\Serialization\BodyContext;
use Milzer\HttpLogger\Core\Serialization\StoredBody;

/**
 * Keeps full copies of bodies that are too large to log inline.
 */
interface BodyStore
{
    /**
     * @param  string  $contents  The already redacted body.
     * @param  string  $extension  File extension without dot, e.g. "json".
     */
    public function store(string $contents, string $extension, BodyContext $context): StoredBody;

    /**
     * Deletes everything stored before the given day.
     *
     * @return int The number of day folders deleted.
     */
    public function prune(DateTimeInterface $before): int;
}
