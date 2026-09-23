<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Serialization\Formatters;

use Milzer\SaloonLogger\Contracts\BodyFormatter;

/**
 * Fallback: any other UTF-8 payload (plain text, HTML, CSV, ...) is logged verbatim.
 */
final class TextFormatter implements BodyFormatter
{
    public function supports(string $mimeType, string $body): bool
    {
        return true;
    }

    public function format(string $body, string $mimeType): string
    {
        return $body;
    }
}
