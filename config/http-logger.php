<?php

declare(strict_types=1);

use Milzer\HttpLogger\Core\LoggingOptions;

return [

    /*
    |--------------------------------------------------------------------------
    | Enable / disable
    |--------------------------------------------------------------------------
    | Master switch for incoming and outgoing logging. Saloon connectors and
    | requests can also opt out individually (ConfiguresLogging::disable()).
    */

    'enabled' => env('HTTP_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Log channel
    |--------------------------------------------------------------------------
    | Any channel from config/logging.php. Null uses the default channel.
    */

    'channel' => env('HTTP_LOGGER_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Write after the response
    |--------------------------------------------------------------------------
    | During web requests, entries are built and written after the response has
    | been sent, so logging adds no latency for the client. In artisan commands
    | and queue workers entries are always written immediately.
    */

    'write_after_response' => env('HTTP_LOGGER_WRITE_AFTER_RESPONSE', true),

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    | Placeholders: {method}, {url}, {status}, any scalar top-level context key
    | (e.g. {supplier}), plus {connector} and {request} for outgoing calls and
    | {route} for incoming requests. Example: 'checkout-to-{supplier}'.
    */

    'messages' => [
        'outgoing' => [
            'request' => env('HTTP_LOGGER_OUTGOING_REQUEST_MESSAGE', 'outgoing-request'),
            'response' => env('HTTP_LOGGER_OUTGOING_RESPONSE_MESSAGE', 'outgoing-response'),
            'failure' => env('HTTP_LOGGER_OUTGOING_FAILURE_MESSAGE', 'outgoing-failure'),
        ],
        'incoming' => [
            'request' => env('HTTP_LOGGER_INCOMING_REQUEST_MESSAGE', 'incoming-request'),
            'response' => env('HTTP_LOGGER_INCOMING_RESPONSE_MESSAGE', 'incoming-response'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log levels (PSR-3)
    |--------------------------------------------------------------------------
    */

    'levels' => [
        'request' => 'info',
        'response' => 'info',        // 1xx - 3xx
        'client_error' => 'warning', // 4xx
        'server_error' => 'error',   // 5xx
        'failure' => 'error',        // outgoing calls without a response: timeouts, DNS, TLS ...
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
    | max_body_bytes:  bodies larger than this are truncated in the log entry
    |                  (after redaction). Keep entries below your backend's
    |                  limit, e.g. 256 KB on Google Cloud Logging. Null
    |                  disables truncation.
    | max_parse_bytes: bodies larger than this are not read at all, to protect
    |                  memory (see storage.max_bytes when storage is enabled).
    */

    'limits' => [
        'max_body_bytes' => 128 * 1024,
        'max_parse_bytes' => 5 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Large body storage
    |--------------------------------------------------------------------------
    | When a body is larger than limits.max_body_bytes, its full (redacted) copy
    | is written to a filesystem disk, one file per body in one folder per day:
    |
    |   {path}/2026/09/24/150116-outgoing-21200c57ff98fa8e-response.json
    |
    | The log entry keeps a truncated preview plus a "body_file" reference.
    |
    | max_bytes:       bodies up to this size are read, redacted and stored.
    |                  Larger ones are omitted.
    | retention_days:  used by `php artisan http-logger:prune` (schedule it
    |                  daily) for disks that can't expire files themselves.
    */

    'storage' => [
        'enabled' => env('HTTP_LOGGER_STORAGE_ENABLED', false),
        'disk' => env('HTTP_LOGGER_STORAGE_DISK', 'local'),
        'path' => env('HTTP_LOGGER_STORAGE_PATH', 'http-logs'),
        'max_bytes' => 20 * 1024 * 1024,
        'retention_days' => env('HTTP_LOGGER_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Incoming requests (LogIncomingRequests middleware)
    |--------------------------------------------------------------------------
    | except:       paths that are never logged ($request->is() patterns).
    | trace_header: a trace id sent by the caller in this header is reused;
    |               otherwise a new one is generated. Null always generates.
    | context:      class implementing ProvidesIncomingLogContext that adds your
    |               own properties (client, api, action, ...) to entries.
    */

    'incoming' => [
        'except' => ['up', 'horizon*', 'telescope*', 'pulse*', '_debugbar*'],
        'trace_header' => 'X-Request-Id',
        'context' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Static context
    |--------------------------------------------------------------------------
    | Added to every entry, both directions. Example: ['project' => 'checkout'].
    */

    'context' => [],

    /*
    |--------------------------------------------------------------------------
    | Custom body formatters
    |--------------------------------------------------------------------------
    | Classes implementing Milzer\HttpLogger\Core\Contracts\BodyFormatter,
    | resolved from the container and tried before the built-in ones.
    */

    'body_formatters' => [],

    /*
    |--------------------------------------------------------------------------
    | Throw on error
    |--------------------------------------------------------------------------
    | By default a failure inside the logger never breaks the application; it is
    | reported to the logger instead. Enable in tests to surface problems.
    */

    'throw_on_error' => env('HTTP_LOGGER_THROW_ON_ERROR', false),

];
