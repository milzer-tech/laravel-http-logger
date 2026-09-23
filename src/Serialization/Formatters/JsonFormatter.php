<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Serialization\Formatters;

use JsonException;
use Milzer\SaloonLogger\Contracts\BodyFormatter;
use Milzer\SaloonLogger\Support\MimeType;

/**
 * Decodes JSON so it shows up as nested, searchable fields in the log entry.
 * Also sniffs JSON that is served with a missing or wrong (text/plain, text/html) content type.
 */
final class JsonFormatter implements BodyFormatter
{
    public function supports(string $mimeType, string $body): bool
    {
        if (MimeType::isJson($mimeType)) {
            return true;
        }

        if (! in_array($mimeType, ['', 'text/plain', 'text/html'], true)) {
            return false;
        }

        // Cheap sniff only; format() falls back to the raw string if it isn't valid JSON after all.
        $first = ltrim($body)[0] ?? '';

        return $first === '{' || $first === '[';
    }

    public function format(string $body, string $mimeType): array|string|int|float|bool|null
    {
        try {
            /** @var array<array-key, mixed>|string|int|float|bool|null */
            return json_decode($body, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Malformed JSON is still worth seeing as-is.
            return $body;
        }
    }
}
