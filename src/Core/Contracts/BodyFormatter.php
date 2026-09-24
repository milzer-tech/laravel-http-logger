<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Contracts;

/**
 * Turns a raw HTTP body into a value that is useful inside a structured log entry.
 *
 * Formatters are tried in order; the first one that supports the body wins.
 * Redaction and size limits are applied to whatever the formatter returns.
 */
interface BodyFormatter
{
    /**
     * @param  string  $mimeType  Lower-cased media type without parameters, e.g. "application/json". Empty when unknown.
     */
    public function supports(string $mimeType, string $body): bool;

    /**
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    public function format(string $body, string $mimeType): array|string|int|float|bool|null;
}
