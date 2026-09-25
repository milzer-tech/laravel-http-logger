<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Saloon\Internal;

use Milzer\HttpLogger\Core\Exchange;
use Milzer\HttpLogger\Core\Serialization\HttpFields;
use Milzer\HttpLogger\Core\Serialization\MultipartPart;
use Milzer\HttpLogger\Core\Serialization\PsrMessageSerializer;
use Milzer\HttpLogger\Core\Serialization\SerializedBody;
use Psr\Http\Message\StreamInterface;
use Saloon\Contracts\Body\BodyRepository;
use Saloon\Data\MultipartValue;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Repositories\Body\MultipartBodyRepository;
use Saloon\Repositories\Body\StreamBodyRepository;
use Stringable;

/**
 * Builds the entry parts for Saloon requests and responses.
 *
 * @internal
 */
final readonly class MessageSerializer
{
    public function __construct(private Exchange $exchange) {}

    /**
     * @return array<string, mixed>
     */
    public function request(PendingRequest $pendingRequest): array
    {
        $options = $this->exchange->options();
        $headers = HttpFields::headers($pendingRequest->headers()->all());
        $uri = $pendingRequest->getUri();

        $http = [
            ...$this->target($pendingRequest),
            'queries' => $this->exchange->redactor()->array(HttpFields::queries($uri->getQuery())),
        ];

        if ($options->logHeaders) {
            $http['headers'] = $this->exchange->redactor()->headers($headers);
        }

        if ($options->logRequestBody) {
            $http = [...$http, ...$this->body($pendingRequest->body(), HttpFields::header($headers, 'Content-Type'))->toArray()];
        }

        return ['http' => $http];
    }

    /**
     * @return array<string, mixed>
     */
    public function response(Response $response): array
    {
        return array_filter([
            ...(new PsrMessageSerializer($this->exchange))->response($response->getPsrRequest(), $response->getPsrResponse()),
            'mocked' => $response->isMocked() ?: null,
            'cached' => $response->isCached() ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{method: string, url: string}
     */
    public function target(PendingRequest $pendingRequest): array
    {
        return [
            'method' => $pendingRequest->getMethod()->value,
            'url' => HttpFields::url($pendingRequest->getUri()),
        ];
    }

    private function body(?BodyRepository $body, ?string $contentType): SerializedBody
    {
        $bodies = $this->exchange->bodies();
        $context = $this->exchange->bodyContext('request');

        if (! $body instanceof BodyRepository || $body->isEmpty()) {
            return new SerializedBody(null);
        }

        if ($body instanceof MultipartBodyRepository) {
            return $bodies->fromParts(array_map($this->part(...), array_values($body->all())));
        }

        if ($body instanceof StreamBodyRepository) {
            $stream = $body->all();

            return $stream instanceof StreamInterface
                ? $bodies->fromStream($stream, $contentType, $context)
                : $bodies->fromResource($stream, $contentType, $context);
        }

        if ($body instanceof Stringable) {
            return $bodies->fromString((string) $body, $contentType, $context);
        }

        return $bodies->describe(sprintf('[body omitted: unsupported body repository %s]', $body::class));
    }

    private function part(MultipartValue $part): MultipartPart
    {
        $value = $part->value;

        if ((is_string($value) || is_numeric($value)) && $part->filename === null) {
            return new MultipartPart($part->name, (string) $value);
        }

        $size = match (true) {
            $value instanceof StreamInterface => $value->getSize(),
            is_resource($value) => fstat($value)['size'] ?? null,
            // MultipartValue only allows streams, resources, strings and numbers.
            default => is_scalar($value) ? strlen((string) $value) : null,
        };

        return new MultipartPart($part->name, filename: $part->filename, size: $size);
    }
}
