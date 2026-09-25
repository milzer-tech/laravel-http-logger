<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core\Serialization;

use Milzer\HttpLogger\Core\Exchange;
use Milzer\HttpLogger\Core\Support\MimeType;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Builds the entry parts for PSR-7 requests and responses, as sent and received by Guzzle
 * (Laravel's HTTP client, Saloon).
 *
 * @internal
 */
final readonly class PsrMessageSerializer
{
    public function __construct(private Exchange $exchange) {}

    /**
     * @return array{http: array<string, mixed>}
     */
    public function request(RequestInterface $request): array
    {
        $options = $this->exchange->options();
        $headers = HttpFields::headers($request->getHeaders());

        $http = [
            ...$this->target($request),
            'queries' => $this->exchange->redactor()->array(HttpFields::queries($request->getUri()->getQuery())),
        ];

        if ($options->logHeaders) {
            $http['headers'] = $this->exchange->redactor()->headers($headers);
        }

        if ($options->logRequestBody) {
            $http = [...$http, ...$this->requestBody($request->getBody(), HttpFields::header($headers, 'Content-Type'))->toArray()];
        }

        return ['http' => $http];
    }

    /**
     * @return array{http: array<string, mixed>}
     */
    public function response(RequestInterface $request, ResponseInterface $response): array
    {
        $options = $this->exchange->options();
        $headers = HttpFields::headers($response->getHeaders());

        $http = [...$this->target($request), 'status' => $response->getStatusCode()];

        if ($options->logHeaders) {
            $http['headers'] = $this->exchange->redactor()->headers($headers);
        }

        if ($options->logResponseBody) {
            $body = $this->exchange->bodies()->fromStream(
                $response->getBody(),
                HttpFields::header($headers, 'Content-Type'),
                $this->exchange->bodyContext('response'),
            );

            $http = [...$http, ...$body->toArray()];
        }

        return ['http' => $http];
    }

    /**
     * @return array{method: string, url: string}
     */
    public function target(RequestInterface $request): array
    {
        return [
            'method' => strtoupper($request->getMethod()),
            'url' => HttpFields::url($request->getUri()),
        ];
    }

    private function requestBody(StreamInterface $stream, ?string $contentType): SerializedBody
    {
        $mimeType = MimeType::essence($contentType);

        // A multipart request stream contains the uploaded files: describe it instead of reading it.
        if (MimeType::isMultipart($mimeType)) {
            $size = $stream->getSize();

            return $this->exchange->bodies()->describe(
                sprintf('[multipart body omitted: %s%s]', $mimeType, $size === null ? '' : ', '.MimeType::humanSize($size)),
            );
        }

        return $this->exchange->bodies()->fromStream($stream, $contentType, $this->exchange->bodyContext('request'));
    }
}
