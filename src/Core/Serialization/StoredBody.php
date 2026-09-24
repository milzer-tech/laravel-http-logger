<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Serialization;

final readonly class StoredBody
{
    public function __construct(
        public string $disk,
        public string $path,
        public int $size,
    ) {}

    /**
     * @return array{disk: string, path: string, size: int}
     */
    public function toArray(): array
    {
        return ['disk' => $this->disk, 'path' => $this->path, 'size' => $this->size];
    }
}
