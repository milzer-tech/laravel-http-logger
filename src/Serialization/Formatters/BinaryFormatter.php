<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Serialization\Formatters;

use Milzer\SaloonLogger\Contracts\BodyFormatter;
use Milzer\SaloonLogger\Support\MimeType;

/**
 * Replaces binary payloads (PDFs, images, archives, anything that is not valid UTF-8)
 * with a short description, so logs stay readable and encoders don't choke.
 */
final class BinaryFormatter implements BodyFormatter
{
    public function supports(string $mimeType, string $body): bool
    {
        return MimeType::isBinary($mimeType)
            || MimeType::isMultipart($mimeType)
            || ! mb_check_encoding($body, 'UTF-8');
    }

    public function format(string $body, string $mimeType): string
    {
        return sprintf(
            '[%s body omitted: %s, %s]',
            MimeType::isMultipart($mimeType) ? 'multipart' : 'binary',
            $mimeType === '' ? 'unknown type' : $mimeType,
            MimeType::humanSize(strlen($body)),
        );
    }
}
