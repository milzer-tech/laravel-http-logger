<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Serialization;

use Psr\Http\Message\UriInterface;

/**
 * Normalises the pieces every HTTP message has, the same way for both directions.
 */
final class HttpFields
{
    /**
     * Collapses single-value headers to a string, which log viewers display far more readably.
     *
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string|list<string>>
     */
    public static function headers(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $values = array_values(array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                is_array($value) ? $value : [$value],
            ));

            $normalized[(string) $name] = count($values) === 1 ? $values[0] : $values;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }

    /**
     * The URL without query string, fragment or embedded credentials; queries are logged separately.
     */
    public static function url(UriInterface $uri): string
    {
        return (string) $uri->withQuery('')->withFragment('')->withUserInfo('');
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function queries(string $query): array
    {
        parse_str($query, $queries);

        return $queries;
    }
}
