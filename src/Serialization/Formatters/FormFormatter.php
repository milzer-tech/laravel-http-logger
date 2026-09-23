<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Serialization\Formatters;

use Milzer\SaloonLogger\Contracts\BodyFormatter;
use Milzer\SaloonLogger\Support\MimeType;

final class FormFormatter implements BodyFormatter
{
    public function supports(string $mimeType, string $body): bool
    {
        return MimeType::isForm($mimeType);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function format(string $body, string $mimeType): array
    {
        parse_str($body, $fields);

        return $fields;
    }
}
