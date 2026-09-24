<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Serialization;

/**
 * A body ready for a log entry: the inline value plus, for large bodies, where the full copy is stored.
 */
final readonly class SerializedBody
{
    /**
     * @param  array<array-key, mixed>|string|int|float|bool|null  $value
     */
    public function __construct(
        public array|string|int|float|bool|null $value,
        public ?StoredBody $file = null,
        public ?string $storageError = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $fields = ['body' => $this->value];

        if ($this->file instanceof StoredBody) {
            $fields['body_file'] = $this->file->toArray();
        } elseif ($this->storageError !== null) {
            $fields['body_file'] = ['error' => $this->storageError];
        }

        return $fields;
    }
}
