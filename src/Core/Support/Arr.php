<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Support;

/**
 * @internal
 */
final class Arr
{
    /**
     * Merges context arrays: later values win, nested arrays are merged recursively.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function mergeContext(array $base, array $extra): array
    {
        foreach ($extra as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key])
                ? array_replace_recursive($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
