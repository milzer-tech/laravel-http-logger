<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core;

use InvalidArgumentException;
use Milzer\HttpLogger\Core\Contracts\BodyFormatter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Immutable logging configuration. Every "with*" method returns a new instance.
 */
final readonly class LoggingOptions
{
    /**
     * Header names whose values are always masked (matched case-insensitively).
     */
    public const DEFAULT_REDACTED_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'api-key',
        'x-auth-token',
        'x-access-token',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    /**
     * Body/query keys whose values are masked. Matching ignores case and separators,
     * so "card_number" also covers "cardNumber" and "Card-Number". "*" is a wildcard.
     */
    public const DEFAULT_REDACTED_KEYS = [
        '*password*',
        '*secret*',
        '*token*',
        'api_key',
        'apikey',
        'private_key',
        'authorization',
        'card_number',
        'cardnumber',
        'pan',
        'cvv',
        'cvv2',
        'cvc',
        'cvc2',
        'security_code',
        'iban',
    ];

    private const LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /**
     * @param  list<string>  $redactHeaders
     * @param  list<string>  $redactKeys
     * @param  array<string, mixed>  $context  Static context added to every entry (connector/request context wins).
     * @param  list<BodyFormatter>  $bodyFormatters  Custom formatters, tried before the built-in ones.
     */
    public function __construct(
        public bool $enabled = true,
        public ?LoggerInterface $logger = null,
        public bool $logRequests = true,
        public bool $logResponses = true,
        public bool $logFailures = true,
        public bool $logHeaders = true,
        public bool $logRequestBody = true,
        public bool $logResponseBody = true,
        public string $outgoingRequestMessage = 'outgoing-request',
        public string $outgoingResponseMessage = 'outgoing-response',
        public string $outgoingFailureMessage = 'outgoing-failure',
        public string $incomingRequestMessage = 'incoming-request',
        public string $incomingResponseMessage = 'incoming-response',
        public string $requestLevel = LogLevel::INFO,
        public string $responseLevel = LogLevel::INFO,
        public string $clientErrorLevel = LogLevel::WARNING,
        public string $serverErrorLevel = LogLevel::ERROR,
        public string $failureLevel = LogLevel::ERROR,
        public array $redactHeaders = self::DEFAULT_REDACTED_HEADERS,
        public array $redactKeys = self::DEFAULT_REDACTED_KEYS,
        public string $redactionMask = '[REDACTED]',
        public ?int $maxBodyBytes = 128 * 1024,
        public int $maxParseBytes = 5 * 1024 * 1024,
        public bool $storeLargeBodies = true,
        public int $maxStoredBodyBytes = 20 * 1024 * 1024,
        public array $context = [],
        public array $bodyFormatters = [],
        public bool $throwOnError = false,
    ) {
        foreach ([$requestLevel, $responseLevel, $clientErrorLevel, $serverErrorLevel, $failureLevel] as $level) {
            if (! in_array($level, self::LEVELS, true)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid PSR-3 log level.', $level));
            }
        }

        if ($maxBodyBytes !== null && $maxBodyBytes < 0) {
            throw new InvalidArgumentException('maxBodyBytes must be null (unlimited) or a positive integer.');
        }

        if ($maxParseBytes < 0) {
            throw new InvalidArgumentException('maxParseBytes must be a positive integer.');
        }

        if ($maxStoredBodyBytes < 0) {
            throw new InvalidArgumentException('maxStoredBodyBytes must be a positive integer.');
        }
    }

    /**
     * Build options from a plain array, e.g. the Laravel config file.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $d = new self;

        $messages = self::section($config, 'messages');
        $outgoing = self::section($messages, 'outgoing');
        $incoming = self::section($messages, 'incoming');
        $storage = self::section($config, 'storage');
        $levels = self::section($config, 'levels');
        $log = self::section($config, 'log');
        $redact = self::section($config, 'redact');
        $limits = self::section($config, 'limits');

        return new self(
            enabled: self::bool($config['enabled'] ?? null, $d->enabled),
            logRequests: self::bool($log['requests'] ?? null, $d->logRequests),
            logResponses: self::bool($log['responses'] ?? null, $d->logResponses),
            logFailures: self::bool($log['failures'] ?? null, $d->logFailures),
            logHeaders: self::bool($log['headers'] ?? null, $d->logHeaders),
            logRequestBody: self::bool($log['request_body'] ?? null, $d->logRequestBody),
            logResponseBody: self::bool($log['response_body'] ?? null, $d->logResponseBody),
            outgoingRequestMessage: self::string($outgoing['request'] ?? null, $d->outgoingRequestMessage),
            outgoingResponseMessage: self::string($outgoing['response'] ?? null, $d->outgoingResponseMessage),
            outgoingFailureMessage: self::string($outgoing['failure'] ?? null, $d->outgoingFailureMessage),
            incomingRequestMessage: self::string($incoming['request'] ?? null, $d->incomingRequestMessage),
            incomingResponseMessage: self::string($incoming['response'] ?? null, $d->incomingResponseMessage),
            requestLevel: self::string($levels['request'] ?? null, $d->requestLevel),
            responseLevel: self::string($levels['response'] ?? null, $d->responseLevel),
            clientErrorLevel: self::string($levels['client_error'] ?? null, $d->clientErrorLevel),
            serverErrorLevel: self::string($levels['server_error'] ?? null, $d->serverErrorLevel),
            failureLevel: self::string($levels['failure'] ?? null, $d->failureLevel),
            redactHeaders: array_key_exists('headers', $redact) ? self::stringList($redact['headers']) : $d->redactHeaders,
            redactKeys: array_key_exists('keys', $redact) ? self::stringList($redact['keys']) : $d->redactKeys,
            redactionMask: self::string($redact['mask'] ?? null, $d->redactionMask),
            maxBodyBytes: array_key_exists('max_body_bytes', $limits)
                ? self::nullableInt($limits['max_body_bytes'])
                : $d->maxBodyBytes,
            maxParseBytes: self::nullableInt($limits['max_parse_bytes'] ?? null) ?? $d->maxParseBytes,
            maxStoredBodyBytes: self::nullableInt($storage['max_bytes'] ?? null) ?? $d->maxStoredBodyBytes,
            context: self::section($config, 'context'),
            bodyFormatters: self::formatterList($config['body_formatters'] ?? []),
            throwOnError: self::bool($config['throw_on_error'] ?? null, $d->throwOnError),
        );
    }

    /**
     * Return a copy with the given properties replaced, e.g. ->with(logHeaders: false).
     */
    public function with(mixed ...$changes): self
    {
        // Values are type-checked by the constructor at runtime (strict_types), unknown names throw.
        // @phpstan-ignore argument.type
        return new self(...array_replace(get_object_vars($this), $changes));
    }

    public function disable(): self
    {
        return $this->with(enabled: false);
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return $this->with(logger: $logger);
    }

    /**
     * Messages for calls the application makes (Saloon). Only the ones you pass change.
     * Placeholders: {connector}, {request}, {method}, {url}, {status} and any scalar
     * top-level context key, e.g. "checkout-to-{supplier}".
     */
    public function withOutgoingMessages(?string $request = null, ?string $response = null, ?string $failure = null): self
    {
        return $this->with(
            outgoingRequestMessage: $request ?? $this->outgoingRequestMessage,
            outgoingResponseMessage: $response ?? $this->outgoingResponseMessage,
            outgoingFailureMessage: $failure ?? $this->outgoingFailureMessage,
        );
    }

    /**
     * Messages for requests the application receives. Only the ones you pass change.
     * Placeholders: {method}, {url}, {status}, {route} and any scalar top-level context key.
     */
    public function withIncomingMessages(?string $request = null, ?string $response = null): self
    {
        return $this->with(
            incomingRequestMessage: $request ?? $this->incomingRequestMessage,
            incomingResponseMessage: $response ?? $this->incomingResponseMessage,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): self
    {
        return $this->with(context: array_replace_recursive($this->context, $context));
    }

    public function redactHeaders(string ...$headers): self
    {
        return $this->with(redactHeaders: array_values(array_unique([...$this->redactHeaders, ...$headers])));
    }

    public function redactKeys(string ...$keys): self
    {
        return $this->with(redactKeys: array_values(array_unique([...$this->redactKeys, ...$keys])));
    }

    public function withoutHeaders(): self
    {
        return $this->with(logHeaders: false);
    }

    public function withoutBodies(): self
    {
        return $this->with(logRequestBody: false, logResponseBody: false);
    }

    public function withoutRequestBody(): self
    {
        return $this->with(logRequestBody: false);
    }

    public function withoutResponseBody(): self
    {
        return $this->with(logResponseBody: false);
    }

    public function withMaxBodyBytes(?int $bytes): self
    {
        return $this->with(maxBodyBytes: $bytes);
    }

    /**
     * Keep truncating large bodies, but don't store full copies (e.g. for payment requests).
     */
    public function withoutBodyStorage(): self
    {
        return $this->with(storeLargeBodies: false);
    }

    public function withBodyFormatter(BodyFormatter $formatter): self
    {
        return $this->with(bodyFormatters: [$formatter, ...$this->bodyFormatters]);
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @return array<string, mixed>
     */
    private static function section(array $config, string $key): array
    {
        $section = [];

        foreach (is_array($config[$key] ?? null) ? $config[$key] : [] as $name => $value) {
            $section[(string) $name] = $value;
        }

        return $section;
    }

    private static function bool(mixed $value, bool $default): bool
    {
        return $value === null ? $default : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    private static function string(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        $list = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_scalar($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    /**
     * @return list<BodyFormatter>
     */
    private static function formatterList(mixed $value): array
    {
        $formatters = [];

        foreach ((array) $value as $formatter) {
            if (is_string($formatter) && is_subclass_of($formatter, BodyFormatter::class)) {
                $formatter = new $formatter;
            }

            if (! $formatter instanceof BodyFormatter) {
                throw new InvalidArgumentException(sprintf('Body formatters must implement %s.', BodyFormatter::class));
            }

            $formatters[] = $formatter;
        }

        return $formatters;
    }
}
