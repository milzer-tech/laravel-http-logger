<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Serialization;

use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\Redaction\Redactor;
use Psr\Http\Message\UriInterface;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;

/**
 * Builds the "http" section of a log entry for requests and responses.
 *
 * @internal
 */
final readonly class MessageSerializer
{
    private BodySerializer $bodies;

    public function __construct(
        private LoggingOptions $options,
        private Redactor $redactor,
    ) {
        $this->bodies = new BodySerializer(
            $redactor,
            $options->maxBodyBytes,
            $options->maxParseBytes,
            $options->bodyFormatters,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function request(PendingRequest $pendingRequest): array
    {
        $headers = $this->normalizeHeaders($pendingRequest->headers()->all());

        $uri = $pendingRequest->getUri();

        $http = [
            ...$this->target($pendingRequest->getMethod()->value, $uri),
            'queries' => $this->queries($uri),
        ];

        if ($this->options->logHeaders) {
            $http['headers'] = $this->redactor->headers($headers);
        }

        if ($this->options->logRequestBody) {
            $http['body'] = $this->bodies->fromRepository($pendingRequest->body(), $this->header($headers, 'Content-Type'));
        }

        return $http;
    }

    /**
     * @return array<string, mixed>
     */
    public function response(Response $response): array
    {
        $psrRequest = $response->getPsrRequest();
        $psrResponse = $response->getPsrResponse();
        $headers = $this->normalizeHeaders($psrResponse->getHeaders());

        $http = [
            ...$this->target($psrRequest->getMethod(), $psrRequest->getUri()),
            'status' => $response->status(),
        ];

        if ($this->options->logHeaders) {
            $http['headers'] = $this->redactor->headers($headers);
        }

        if ($this->options->logResponseBody) {
            $http['body'] = $this->bodies->fromStream($psrResponse->getBody(), $this->header($headers, 'Content-Type'));
        }

        return $http;
    }

    /**
     * @return array{method: string, url: string}
     */
    public function target(string $method, UriInterface $uri): array
    {
        return [
            'method' => strtoupper($method),
            'url' => $this->url($uri),
        ];
    }

    /**
     * The URL without query string, fragment or embedded credentials; queries are logged separately.
     */
    private function url(UriInterface $uri): string
    {
        return (string) $uri->withQuery('')->withFragment('')->withUserInfo('');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function queries(UriInterface $uri): array
    {
        parse_str($uri->getQuery(), $queries);

        return $this->redactor->array($queries);
    }

    /**
     * Collapses single-value headers to a string, as log viewers display them far more readably.
     *
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string|list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $values = array_values(array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                is_array($value) ? $value : [$value],
            ));

            $normalized[(string) $name] = count($values) === 1 ? $values[0] : $values;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }
}
