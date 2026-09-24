<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Serialization;

use DateTimeImmutable;
use Milzer\HttpLogger\Core\Direction;

/**
 * Identifies a body: to which exchange and which side (request/response) it belongs.
 */
final readonly class BodyContext
{
    /**
     * @param  'request'|'response'  $part
     */
    public function __construct(
        public string $correlationId,
        public Direction $direction,
        public string $part,
        public DateTimeImmutable $occurredAt,
    ) {}
}
