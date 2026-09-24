<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Serialization;

/**
 * One field of a multipart body: either a text value or a file (described by its size).
 */
final readonly class MultipartPart
{
    public function __construct(
        public string $name,
        public ?string $value = null,
        public ?string $filename = null,
        public ?int $size = null,
    ) {}
}
