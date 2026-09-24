<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Tests\Support;

use OutOfBoundsException;

final readonly class LogRecord
{
    /**
     * @param  array<array-key, mixed>  $context
     */
    public function __construct(
        public string $level,
        public string $message,
        public array $context,
    ) {}

    /**
     * Reads a context value by dot path, e.g. "http.body". Fails loudly when the path is missing.
     */
    public function get(string $path): mixed
    {
        $value = $this->context;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                throw new OutOfBoundsException(sprintf('Log context has no "%s".', $path));
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Reads a context value that must be a string.
     */
    public function string(string $path): string
    {
        $value = $this->get($path);

        return is_string($value) ? $value : throw new OutOfBoundsException(sprintf('Log context "%s" is not a string.', $path));
    }

    public function has(string $path): bool
    {
        try {
            $this->get($path);

            return true;
        } catch (OutOfBoundsException) {
            return false;
        }
    }
}
