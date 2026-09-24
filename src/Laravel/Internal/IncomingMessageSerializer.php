<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Laravel\Internal;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Milzer\HttpLogger\Core\Exchange;
use Milzer\HttpLogger\Core\Serialization\HttpFields;
use Milzer\HttpLogger\Core\Serialization\MultipartPart;
use Milzer\HttpLogger\Core\Serialization\SerializedBody;
use Milzer\HttpLogger\Core\Support\MimeType;
use Milzer\HttpLogger\Laravel\Contracts\ProvidesIncomingLogContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the entry parts for requests the application receives and the responses it returns.
 *
 * @internal
 */
final readonly class IncomingMessageSerializer
{
    public function __construct(
        private Exchange $exchange,
        private AuthFactory $auth,
        private ?ProvidesIncomingLogContext $context = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function request(Request $request): array
    {
        $options = $this->exchange->options();
        $headers = HttpFields::headers($request->headers->all());

        $http = [
            ...$this->target($request),
            'queries' => $this->exchange->redactor()->array($request->query->all()),
        ];

        if ($options->logHeaders) {
            $http['headers'] = $this->exchange->redactor()->headers($headers);
        }

        if ($options->logRequestBody) {
            $http = [...$http, ...$this->requestBody($request)->toArray()];
        }

        return $this->parts($request, $http);
    }

    /**
     * @return array<string, mixed>
     */
    public function response(Request $request, Response $response): array
    {
        $options = $this->exchange->options();
        $headers = HttpFields::headers($response->headers->allPreserveCase());

        $http = [...$this->target($request), 'status' => $response->getStatusCode()];

        if ($options->logHeaders) {
            $http['headers'] = $this->exchange->redactor()->headers($headers);
        }

        if ($options->logResponseBody) {
            $http = [...$http, ...$this->responseBody($response, HttpFields::header($headers, 'Content-Type'))->toArray()];
        }

        return $this->parts($request, $http);
    }

    /**
     * @param  array<string, mixed>  $http
     * @return array<string, mixed>
     */
    private function parts(Request $request, array $http): array
    {
        $guard = $this->auth->guard();

        return [
            // Your context first, so it can never overwrite the package's own keys.
            ...($this->context?->incomingLogContext($request) ?? []),
            'http' => $http,
            // Only when authentication already happened: never trigger a user lookup just for logging.
            ...($guard->hasUser() ? ['user_id' => $guard->id()] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function target(Request $request): array
    {
        // Not $request->route(): it is null before routing (e.g. in global middleware).
        $route = ($request->getRouteResolver())();

        return array_filter([
            'method' => $request->getMethod(),
            'url' => $request->url(),
            'route' => $route instanceof Route ? ($route->getName() ?? $route->uri()) : null,
            'ip' => $request->ip(),
        ], static fn (?string $value): bool => $value !== null);
    }

    private function requestBody(Request $request): SerializedBody
    {
        $contentType = $request->headers->get('Content-Type');
        $bodies = $this->exchange->bodies();

        $content = $request->getContent();
        $parsed = $request->request->count() > 0 || $request->files->count() > 0;

        // PHP parses multipart bodies into fields and files and discards the raw body.
        if (MimeType::isMultipart(MimeType::essence($contentType)) || ($content === '' && $parsed)) {
            return $bodies->fromParts([
                ...$this->fields($request->request->all()),
                ...$this->files($request->files->all()),
            ]);
        }

        return $bodies->fromString($content, $contentType, $this->exchange->bodyContext('request'));
    }

    private function responseBody(Response $response, ?string $contentType): SerializedBody
    {
        $bodies = $this->exchange->bodies();

        if ($response instanceof BinaryFileResponse) {
            $size = $response->getFile()->getSize();

            return $bodies->describe(sprintf('[binary body omitted: file download%s]', $size === false ? '' : ', '.MimeType::humanSize($size)));
        }

        if ($response instanceof StreamedResponse) {
            return $bodies->describe('[body omitted: streamed response]');
        }

        return $bodies->fromString((string) $response->getContent(), $contentType, $this->exchange->bodyContext('response'));
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return list<MultipartPart>
     */
    private function fields(array $fields, string $prefix = ''): array
    {
        $parts = [];

        foreach ($fields as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            $parts = is_array($value)
                ? [...$parts, ...$this->fields($value, $name)]
                : [...$parts, new MultipartPart($name, is_scalar($value) ? (string) $value : '')];
        }

        return $parts;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return list<MultipartPart>
     */
    private function files(array $files, string $prefix = ''): array
    {
        $parts = [];

        foreach ($files as $key => $file) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($file)) {
                $parts = [...$parts, ...$this->files($file, $name)];
            } elseif ($file instanceof UploadedFile) {
                $parts[] = new MultipartPart($name, filename: $file->getClientOriginalName(), size: (int) $file->getSize());
            }
        }

        return $parts;
    }
}
