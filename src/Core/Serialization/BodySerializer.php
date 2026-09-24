<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Serialization;

use Milzer\HttpLogger\Core\Contracts\BodyFormatter;
use Milzer\HttpLogger\Core\Contracts\BodyStore;
use Milzer\HttpLogger\Core\Redaction\Redactor;
use Milzer\HttpLogger\Core\Serialization\Formatters\BinaryFormatter;
use Milzer\HttpLogger\Core\Serialization\Formatters\FormFormatter;
use Milzer\HttpLogger\Core\Serialization\Formatters\JsonFormatter;
use Milzer\HttpLogger\Core\Serialization\Formatters\TextFormatter;
use Milzer\HttpLogger\Core\Serialization\Formatters\XmlFormatter;
use Milzer\HttpLogger\Core\Support\MimeType;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Converts bodies of any content type into a safe, bounded log value.
 *
 * Pipeline: read (without consuming the stream) -> format -> redact -> store (if too large) -> truncate.
 *
 * @internal
 */
final readonly class BodySerializer
{
    /** @var list<BodyFormatter> */
    private array $formatters;

    private TextFormatter $fallback;

    /** The largest body that is read at all. */
    private int $readLimit;

    /**
     * @param  list<BodyFormatter>  $customFormatters
     */
    public function __construct(
        private Redactor $redactor,
        private ?int $maxBodyBytes,
        int $maxParseBytes,
        array $customFormatters = [],
        private ?BodyStore $store = null,
        int $maxStoredBodyBytes = 0,
    ) {
        $this->formatters = [
            ...$customFormatters,
            new BinaryFormatter,
            new JsonFormatter,
            new FormFormatter,
            new XmlFormatter,
        ];

        $this->fallback = new TextFormatter;
        $this->readLimit = $store instanceof BodyStore ? max($maxParseBytes, $maxStoredBodyBytes) : $maxParseBytes;
    }

    public function fromString(string $body, ?string $contentType, BodyContext $context): SerializedBody
    {
        if ($body === '') {
            return new SerializedBody(null);
        }

        if (strlen($body) > $this->readLimit) {
            return new SerializedBody($this->tooLarge(strlen($body), $contentType));
        }

        $mimeType = MimeType::essence($contentType);
        $value = $this->redact($this->formatterFor($mimeType, $body)->format($body, $mimeType));

        return $this->bounded($value, $mimeType, $context);
    }

    /**
     * Reads a PSR-7 stream and restores its position, so the application can still
     * consume it. Non-seekable streams (e.g. streamed downloads) are never read.
     */
    public function fromStream(StreamInterface $stream, ?string $contentType, BodyContext $context): SerializedBody
    {
        $size = $stream->getSize();

        if ($size === 0) {
            return new SerializedBody(null);
        }

        if (! $stream->isSeekable() || ! $stream->isReadable()) {
            return $this->describe(sprintf('[body omitted: non-seekable stream%s]', $size === null ? '' : ', '.MimeType::humanSize($size)));
        }

        if ($size !== null && $size > $this->readLimit) {
            return new SerializedBody($this->tooLarge($size, $contentType));
        }

        $position = $stream->tell();
        $stream->rewind();

        $contents = '';
        while (! $stream->eof() && strlen($contents) <= $this->readLimit) {
            $contents .= $stream->read(65536);
        }

        $stream->seek($position);

        if (strlen($contents) > $this->readLimit) {
            return new SerializedBody($this->tooLarge(null, $contentType));
        }

        return $this->fromString($contents, $contentType, $context);
    }

    /**
     * Reads a raw PHP stream resource and restores its position.
     */
    public function fromResource(mixed $resource, ?string $contentType, BodyContext $context): SerializedBody
    {
        // A closed resource is no longer a resource; treat it like a stream we must not read.
        if (! is_resource($resource) || ! stream_get_meta_data($resource)['seekable']) {
            return $this->describe('[body omitted: non-seekable stream]');
        }

        $position = (int) ftell($resource);
        rewind($resource);
        // stream_get_contents() only returns false on a read error; log that as an empty body.
        $contents = (string) stream_get_contents($resource, $this->readLimit + 1);
        fseek($resource, $position);

        if (strlen($contents) > $this->readLimit) {
            return new SerializedBody($this->tooLarge(null, $contentType));
        }

        return $this->fromString($contents, $contentType, $context);
    }

    /**
     * Multipart bodies are summarised part by part: text fields are shown (and redacted),
     * files are described instead of dumped.
     *
     * @param  list<MultipartPart>  $parts
     */
    public function fromParts(array $parts): SerializedBody
    {
        return new SerializedBody(array_map(function (MultipartPart $part): array {
            $contents = match (true) {
                $this->redactor->isSensitiveKey($part->name) => $this->redactor->mask(),
                $part->value !== null => $this->truncate($this->redactor->string($part->value)),
                default => sprintf('[file omitted%s]', $part->size === null ? '' : ': '.MimeType::humanSize($part->size)),
            };

            return array_filter([
                'name' => $part->name,
                'filename' => $part->filename,
                'contents' => $contents,
            ], static fn (?string $value): bool => $value !== null);
        }, $parts));
    }

    /**
     * A body that is described rather than logged, e.g. "[body omitted: streamed response]".
     */
    public function describe(string $description): SerializedBody
    {
        return new SerializedBody($description);
    }

    private function formatterFor(string $mimeType, string $body): BodyFormatter
    {
        foreach ($this->formatters as $formatter) {
            if ($formatter->supports($mimeType, $body)) {
                return $formatter;
            }
        }

        return $this->fallback;
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
     * An oversized body is stored in full (if a store is configured) and logged as a truncated
     * preview. Redaction has already happened, so neither copy contains secrets.
     *
     * @param  array<array-key, mixed>|string|int|float|bool|null  $value
     */
    private function bounded(array|string|int|float|bool|null $value, string $mimeType, BodyContext $context): SerializedBody
    {
        if ($this->maxBodyBytes === null || ! is_array($value) && ! is_string($value)) {
            return new SerializedBody($value);
        }

        $encoded = is_string($value) ? $value : $this->json($value, pretty: false);

        if (strlen($encoded) <= $this->maxBodyBytes) {
            return new SerializedBody($value);
        }

        $preview = $this->truncate($encoded);

        if (! $this->store instanceof BodyStore) {
            return new SerializedBody($preview);
        }

        try {
            $file = $this->store->store(
                is_string($value) ? $value : $this->json($value, pretty: true),
                MimeType::extension($mimeType, is_array($value)),
                $context,
            );

            return new SerializedBody($preview, $file);
        } catch (Throwable $throwable) {
            return new SerializedBody($preview, storageError: $throwable->getMessage());
        }
    }

    private function truncate(string $value): string
    {
        if ($this->maxBodyBytes === null || strlen($value) <= $this->maxBodyBytes) {
            return $value;
        }

        return mb_strcut($value, 0, $this->maxBodyBytes, 'UTF-8')
            .sprintf('... [truncated, %s of %s shown]', MimeType::humanSize($this->maxBodyBytes), MimeType::humanSize(strlen($value)));
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function json(array $value, bool $pretty): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        return (string) json_encode($value, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }

    private function tooLarge(?int $size, ?string $contentType): string
    {
        $mimeType = MimeType::essence($contentType);

        return sprintf(
            '[body omitted: %s larger than the %s limit%s]',
            $size === null ? 'stream' : MimeType::humanSize($size),
            MimeType::humanSize($this->readLimit),
            $mimeType === '' ? '' : ', '.$mimeType,
        );
    }
}
