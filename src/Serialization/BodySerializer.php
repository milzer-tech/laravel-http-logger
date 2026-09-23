<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Serialization;

use Milzer\SaloonLogger\Contracts\BodyFormatter;
use Milzer\SaloonLogger\Redaction\Redactor;
use Milzer\SaloonLogger\Serialization\Formatters\BinaryFormatter;
use Milzer\SaloonLogger\Serialization\Formatters\FormFormatter;
use Milzer\SaloonLogger\Serialization\Formatters\JsonFormatter;
use Milzer\SaloonLogger\Serialization\Formatters\TextFormatter;
use Milzer\SaloonLogger\Serialization\Formatters\XmlFormatter;
use Milzer\SaloonLogger\Support\MimeType;
use Psr\Http\Message\StreamInterface;
use Saloon\Contracts\Body\BodyRepository;
use Saloon\Data\MultipartValue;
use Saloon\Repositories\Body\MultipartBodyRepository;
use Saloon\Repositories\Body\StreamBodyRepository;
use Stringable;

/**
 * Converts request/response bodies of any content type into a safe, bounded log value.
 *
 * Pipeline: read (without consuming the stream) -> format -> redact -> truncate.
 *
 * @internal
 */
final class BodySerializer
{
    /** @var list<BodyFormatter> */
    private readonly array $formatters;

    /**
     * @param  list<BodyFormatter>  $customFormatters
     */
    public function __construct(
        private readonly Redactor $redactor,
        private readonly ?int $maxBodyBytes,
        private readonly int $maxParseBytes,
        array $customFormatters = [],
    ) {
        $this->formatters = [
            ...$customFormatters,
            new BinaryFormatter,
            new JsonFormatter,
            new FormFormatter,
            new XmlFormatter,
            new TextFormatter,
        ];
    }

    /**
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    public function fromRepository(?BodyRepository $body, ?string $contentType): array|string|int|float|bool|null
    {
        if ($body === null || $body->isEmpty()) {
            return null;
        }

        if ($body instanceof MultipartBodyRepository) {
            return $this->fromMultipart($body);
        }

        if ($body instanceof StreamBodyRepository) {
            $stream = $body->all();

            return $stream instanceof StreamInterface
                ? $this->fromStream($stream, $contentType)
                : $this->fromResource($stream, $contentType);
        }

        if ($body instanceof Stringable) {
            return $this->fromString((string) $body, $contentType);
        }

        return sprintf('[body omitted: unsupported body repository %s]', $body::class);
    }

    /**
     * Reads a PSR-7 stream and restores its position, so Saloon and the caller can
     * still consume it. Non-seekable streams (e.g. streamed downloads) are never read.
     *
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    public function fromStream(StreamInterface $stream, ?string $contentType): array|string|int|float|bool|null
    {
        $size = $stream->getSize();

        if ($size === 0) {
            return null;
        }

        if (! $stream->isSeekable() || ! $stream->isReadable()) {
            return sprintf('[body omitted: non-seekable stream%s]', $size === null ? '' : ', '.MimeType::humanSize($size));
        }

        if ($size !== null && $size > $this->maxParseBytes) {
            return $this->tooLarge($size, $contentType);
        }

        $position = $stream->tell();
        $stream->rewind();

        $contents = '';
        while (! $stream->eof() && strlen($contents) <= $this->maxParseBytes) {
            $contents .= $stream->read(65536);
        }

        $stream->seek($position);

        if (strlen($contents) > $this->maxParseBytes) {
            return $this->tooLarge(null, $contentType);
        }

        return $this->fromString($contents, $contentType);
    }

    /**
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    public function fromString(string $body, ?string $contentType): array|string|int|float|bool|null
    {
        if ($body === '') {
            return null;
        }

        if (strlen($body) > $this->maxParseBytes) {
            return $this->tooLarge(strlen($body), $contentType);
        }

        $mimeType = MimeType::essence($contentType);

        foreach ($this->formatters as $formatter) {
            if ($formatter->supports($mimeType, $body)) {
                return $this->limit($this->redact($formatter->format($body, $mimeType)));
            }
        }

        return null;
    }

    /**
     * Multipart bodies are summarised part-by-part: text fields are shown (and redacted),
     * files are described instead of dumped.
     *
     * @return list<array<string, mixed>>
     */
    private function fromMultipart(MultipartBodyRepository $body): array
    {
        return array_map(function (MultipartValue $part): array {
            $value = $part->value;
            $isText = (is_string($value) || is_numeric($value)) && $part->filename === null;

            $contents = match (true) {
                $this->redactor->isSensitiveKey($part->name) => $this->redactor->mask(),
                $isText => $this->limit($this->redactor->string((string) $value)),
                default => $this->describePart($value),
            };

            return array_filter([
                'name' => $part->name,
                'filename' => $part->filename,
                'contents' => $contents,
            ], static fn (mixed $v): bool => $v !== null);
        }, array_values($body->all()));
    }

    private function describePart(mixed $value): string
    {
        $size = match (true) {
            $value instanceof StreamInterface => $value->getSize(),
            is_resource($value) => fstat($value)['size'] ?? null,
            is_scalar($value) => strlen((string) $value),
            default => null,
        };

        return sprintf('[file omitted%s]', $size === null ? '' : ': '.MimeType::humanSize($size));
    }

    /**
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    private function fromResource(mixed $resource, ?string $contentType): array|string|int|float|bool|null
    {
        if (! is_resource($resource)) {
            return null;
        }

        $meta = stream_get_meta_data($resource);

        if (! $meta['seekable']) {
            return '[body omitted: non-seekable stream]';
        }

        $position = ftell($resource);
        rewind($resource);
        $contents = stream_get_contents($resource, $this->maxParseBytes + 1);
        fseek($resource, $position === false ? 0 : $position);

        if ($contents === false) {
            return '[body omitted: unreadable stream]';
        }

        if (strlen($contents) > $this->maxParseBytes) {
            return $this->tooLarge(null, $contentType);
        }

        return $this->fromString($contents, $contentType);
    }

    /**
     * @param  array<array-key, mixed>|string|int|float|bool|null  $value
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    private function redact(array|string|int|float|bool|null $value): array|string|int|float|bool|null
    {
        return match (true) {
            is_array($value) => $this->redactor->array($value),
            is_string($value) => $this->redactor->string($value),
            default => $value,
        };
    }

    /**
     * Keeps entries below the log backend's size limit (e.g. 256 KB on Google Cloud Logging).
     * Oversized structured bodies are re-encoded and cut, which is why redaction happens first.
     *
     * @param  array<array-key, mixed>|string|int|float|bool|null  $value
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    private function limit(array|string|int|float|bool|null $value): array|string|int|float|bool|null
    {
        if ($this->maxBodyBytes === null || ! (is_array($value) || is_string($value))) {
            return $value;
        }

        $encoded = is_string($value)
            ? $value
            : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $size = strlen($encoded);

        if ($size <= $this->maxBodyBytes) {
            return $value;
        }

        return mb_strcut($encoded, 0, $this->maxBodyBytes, 'UTF-8')
            .sprintf('... [truncated, %s of %s shown]', MimeType::humanSize($this->maxBodyBytes), MimeType::humanSize($size));
    }

    private function tooLarge(?int $size, ?string $contentType): string
    {
        $mimeType = MimeType::essence($contentType);

        return sprintf(
            '[body omitted: %s larger than the %s parse limit%s]',
            $size === null ? 'stream' : MimeType::humanSize($size),
            MimeType::humanSize($this->maxParseBytes),
            $mimeType === '' ? '' : ', '.$mimeType,
        );
    }
}
