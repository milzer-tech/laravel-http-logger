<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TemporaryDirectory
{
    private static ?string $current = null;

    /**
     * The directory of the current test, created on first use.
     */
    public static function current(): string
    {
        return self::$current ??= self::create();
    }

    public static function cleanup(): void
    {
        if (self::$current !== null) {
            self::delete(self::$current);
            self::$current = null;
        }
    }

    public static function create(): string
    {
        $path = sys_get_temp_dir().'/http-logger-'.bin2hex(random_bytes(6));
        mkdir($path, 0777, true);

        return $path;
    }

    public static function delete(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
