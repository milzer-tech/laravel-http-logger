<?php

declare(strict_types=1);

use Milzer\SaloonLogger\LoggingOptions;

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / disable
    |--------------------------------------------------------------------------
    | Master switch. Connectors/requests can also opt out individually by
    | implementing ConfiguresLogging and returning $options->disable().
    */

    'enabled' => env('SALOON_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    | Any channel from config/logging.php. Null uses the default channel.
    */

    'channel' => env('SALOON_LOGGER_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    | The defaults are fine for most apps; override them here, via env, or per
    | connector/request with $options->withMessages(...).
    |
    | Placeholders: {connector}, {request}, {method}, {url}, {status} and any
    | scalar top-level context key, e.g. 'checkout-to-{supplier}'.
    */

    'messages' => [
        'request' => env('SALOON_LOGGER_REQUEST_MESSAGE', 'saloon-request'),
        'response' => env('SALOON_LOGGER_RESPONSE_MESSAGE', 'saloon-response'),
        'failure' => env('SALOON_LOGGER_FAILURE_MESSAGE', 'saloon-failure'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log levels (PSR-3)
    |--------------------------------------------------------------------------
    */

    'levels' => [
        'request' => 'info',
        'response' => 'info',       // 1xx - 3xx
        'client_error' => 'warning', // 4xx
        'server_error' => 'error',   // 5xx
        'failure' => 'error',        // connection errors, timeouts, DNS, TLS ...
    ],

    /*
    |--------------------------------------------------------------------------
    | What to log
    |--------------------------------------------------------------------------
    */

    'log' => [
        'requests' => true,
        'responses' => true,
        'failures' => true,
        'headers' => true,
        'request_body' => true,
        'response_body' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    | Keys match case- and separator-insensitively ("card_number" also matches
    | "cardNumber" and "Card-Number") in JSON, form, query, multipart and XML
    | bodies. "*" is a wildcard. Extend the defaults rather than replacing them:
    |
    |   'keys' => [...LoggingOptions::DEFAULT_REDACTED_KEYS, 'passport_number'],
    */

    'redact' => [
        'headers' => LoggingOptions::DEFAULT_REDACTED_HEADERS,
        'keys' => LoggingOptions::DEFAULT_REDACTED_KEYS,
        'mask' => '[REDACTED]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Size limits (bytes)
    |--------------------------------------------------------------------------
    | max_body_bytes:  bodies larger than this are truncated (after redaction).
    |                  Keep entries below your backend's limit, e.g. 256 KB on
    |                  Google Cloud Logging. Null disables truncation.
    | max_parse_bytes: bodies larger than this are not read at all, to protect
    |                  memory. They are replaced by a short description.
    */

    'limits' => [
        'max_body_bytes' => 128 * 1024,
        'max_parse_bytes' => 5 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Static context
    |--------------------------------------------------------------------------
    | Added to every entry. Connector/request context (ProvidesLogContext)
    | is merged on top. Example: ['project' => 'checkout'].
    */

    'context' => [],

    /*
    |--------------------------------------------------------------------------
    | Custom body formatters
    |--------------------------------------------------------------------------
    | Classes implementing Milzer\SaloonLogger\Contracts\BodyFormatter, resolved
    | from the container and tried before the built-in JSON/XML/form/text ones.
    */

    'body_formatters' => [],

    /*
    |--------------------------------------------------------------------------
    | Throw on error
    |--------------------------------------------------------------------------
    | By default a failure inside the logger never breaks the HTTP call; it is
    | reported to the logger instead. Enable in tests to surface problems.
    */

    'throw_on_error' => env('SALOON_LOGGER_THROW_ON_ERROR', false),

];
