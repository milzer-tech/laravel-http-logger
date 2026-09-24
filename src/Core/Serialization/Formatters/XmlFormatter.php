<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Serialization\Formatters;

use Milzer\HttpLogger\Core\Contracts\BodyFormatter;
use Milzer\HttpLogger\Core\Support\MimeType;

/**
 * Keeps XML/SOAP as a string (the most faithful and readable form in log viewers).
 * Sensitive elements and attributes are masked afterwards by the redactor.
 */
final class XmlFormatter implements BodyFormatter
{
    public function supports(string $mimeType, string $body): bool
    {
        if (MimeType::isXml($mimeType)) {
            return true;
        }

        return in_array($mimeType, ['', 'text/plain'], true)
            && str_starts_with(ltrim($body), '<?xml');
    }

    public function format(string $body, string $mimeType): string
    {
        return trim($body);
    }
}
