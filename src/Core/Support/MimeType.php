<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Support;

/**
 * @internal
 */
final class MimeType
{
    /**
     * "Application/JSON; charset=utf-8" => "application/json"
     */
    public static function essence(?string $contentType): string
    {
        if ($contentType === null) {
            return '';
        }

        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    public static function isJson(string $mimeType): bool
    {
        return $mimeType === 'application/json'
            || $mimeType === 'text/json'
            || str_ends_with($mimeType, '+json');
    }

    public static function isXml(string $mimeType): bool
    {
        return $mimeType === 'application/xml'
            || $mimeType === 'text/xml'
            || str_ends_with($mimeType, '+xml');
    }

    public static function isForm(string $mimeType): bool
    {
        return $mimeType === 'application/x-www-form-urlencoded';
    }

    public static function isMultipart(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'multipart/');
    }

    public static function isBinary(string $mimeType): bool
    {
        if (self::isJson($mimeType) || self::isXml($mimeType)) {
            return false;
        }

        foreach (['image/', 'audio/', 'video/', 'font/', 'application/vnd.'] as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }

        return in_array($mimeType, [
            'application/octet-stream',
            'application/pdf',
            'application/zip',
            'application/gzip',
            'application/x-gzip',
            'application/x-tar',
            'application/x-7z-compressed',
            'application/x-protobuf',
            'application/protobuf',
            'application/grpc',
            'application/msword',
            'application/wasm',
        ], true);
    }

    /**
     * File extension for a stored body.
     */
    public static function extension(string $mimeType, bool $structured): string
    {
        return match (true) {
            $structured, self::isJson($mimeType) => 'json',
            self::isXml($mimeType) => 'xml',
            $mimeType === 'text/html' => 'html',
            default => 'txt',
        };
    }

    public static function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 2 => round($bytes / 1024 ** 2, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024, 1).' KB',
            default => $bytes.' B',
        };
    }
}
