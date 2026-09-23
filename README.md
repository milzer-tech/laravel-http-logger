# Saloon Logger

[![tests](https://github.com/milzer/saloon-logger/actions/workflows/tests.yml/badge.svg)](https://github.com/milzer/saloon-logger/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/php-8.2%2B-777bb4)
![Saloon](https://img.shields.io/badge/saloon-v3.10%2B%20%7C%20v4-orange)
![License](https://img.shields.io/badge/license-MIT-green)

A [Saloon](https://docs.saloon.dev) plugin that logs every HTTP request your app sends and every response it gets back. The log entries are **structured and redacted**, and work with any PSR-3 logger.

Add one trait to a connector and every call to that API is logged: the request, the response, how long it took, and any connection failure. Secrets and card data are masked, and large or binary payloads are handled safely. Bodies can be JSON, XML/SOAP, form data, multipart uploads, text, HTML, files or streams.

```php
class RatehawkConnector extends Connector
{
    use HasLogging;
}
```

---

## Table of contents

- [Why](#why)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [What a log entry looks like](#what-a-log-entry-looks-like)
- [Adding context (supplier, client, action…)](#adding-context)
- [Configuration](#configuration)
- [Customising per connector or request](#customising-per-connector-or-request)
- [Log messages and levels](#log-messages-and-levels)
- [How bodies are logged](#how-bodies-are-logged)
- [Redaction](#redaction)
- [Size limits](#size-limits)
- [Failures, retries, async and pools](#failures-retries-async-and-pools)
- [Testing your application](#testing-your-application)
- [Custom body formatters](#custom-body-formatters)
- [How it works](#how-it-works)
- [Known limitations](#known-limitations)
- [Development](#development)
- [Releasing a new version](#releasing-a-new-version)
- [License](#license)

---

## Why

When you integrate with suppliers, "what exactly did we send, and what did they answer?" is the question you ask most often. Logging by hand in every connector tends to go wrong in the same ways:

- log formats differ between projects and connectors,
- tokens, passwords or card numbers end up in the logs,
- a 5 MB XML response or a PDF download blows up the log entry (Google Cloud Logging rejects entries above 256 KB),
- reading the response stream for logging leaves the application with an empty body.

This package solves all of these once, as a [Saloon plugin](https://docs.saloon.dev/installable-plugins/building-your-own-plugins).

## Features

- **One trait, zero boilerplate.** Add `HasLogging` to a connector or a single request.
- **Two correlated entries per call.** The request and the response share a `correlation_id`.
- **Response time** in seconds on every response.
- **Content-aware bodies.** JSON is decoded into searchable fields. XML/SOAP, form, multipart, text and binary each get an appropriate representation.
- **Redaction** of headers, query parameters, body fields, XML elements and attributes, and URL credentials.
- **Size limits.** Bodies are truncated *after* redaction. Huge bodies are never loaded into memory.
- **Stream-safe.** Streams are rewound after reading. Non-seekable streams are never touched.
- **Failures are logged** (timeouts, DNS, connection refused) with the original exception.
- **Your context** (`supplier`, `client`, `api`, `action`, …) is added to every entry.
- **Per-connector/request overrides** for messages, logger/channel, redaction and bodies, or turning logging off.
- **Never breaks your HTTP call.** Errors inside the logger are caught and reported.
- **Laravel integration** (auto-discovery, publishable config). The core works in any framework.
- Supports **Saloon v3.10+ and v4**, **PHP 8.2+**, sync, async, pools and retries.

## Requirements

| | Version |
|---|---|
| PHP | 8.2 or higher |
| Saloon | `^3.10` or `^4.0` |
| Logger | Any PSR-3 logger (Laravel, Monolog, Symfony, …) |
| Laravel (optional) | 11 or 12 |

## Installation

The package is installed straight from GitHub. Add the repository to your application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/milzer/saloon-logger"
        }
    ]
}
```

Then require it:

```bash
composer require milzer/saloon-logger
```

Composer installs the latest tagged release (see [Releasing a new version](#releasing-a-new-version)). To use the unreleased `main` branch, require `milzer/saloon-logger:dev-main`.

### Private repository

If the repository is private, Composer needs a GitHub token with read access to it:

1. Create a [fine-grained personal access token](https://github.com/settings/personal-access-tokens) with **Contents: Read-only** access to the repository.
2. Register it with Composer, either locally (stored in `~/.composer/auth.json`) or in CI:

```bash
composer config --global github-oauth.github.com <your-token>
```

In CI (e.g. GitHub Actions or Cloud Build), set the `COMPOSER_AUTH` environment variable instead:

```bash
COMPOSER_AUTH='{"github-oauth":{"github.com":"<your-token>"}}'
```

Never commit the token or an `auth.json` file to your application's repository.

## Quick start

### Laravel

1. Install the package. The service provider is auto-discovered.
2. Add the plugin to your connector:

```php
use Milzer\SaloonLogger\Plugins\HasLogging;
use Saloon\Http\Connector;

class RatehawkConnector extends Connector
{
    use HasLogging;

    public function resolveBaseUrl(): string
    {
        return 'https://api.ratehawk.com/v1';
    }
}
```

3. Done. Entries go to your default log channel. To use another channel:

```dotenv
SALOON_LOGGER_CHANNEL=suppliers
```

To change the defaults, publish the config file:

```bash
php artisan vendor:publish --tag=saloon-logger-config
```

### Plain PHP / other frameworks

Register a default logger once during bootstrap, then use the trait the same way:

```php
use Milzer\SaloonLogger\LoggingOptions;
use Milzer\SaloonLogger\SaloonLogger;

SaloonLogger::setDefault(new SaloonLogger(
    $psrLogger,                                  // any Psr\Log\LoggerInterface, e.g. Monolog
    new LoggingOptions(context: ['project' => 'checkout']),
));
```

If you use the plugin without registering a logger, you get a clear `MissingLoggerException` on the first request.

## What a log entry looks like

Each call produces **two entries**, one for the request and one for the response. This is how they look in Google Cloud Logging when written by Laravel's JSON formatter (`jsonPayload.context`):

**Request** (`message: "saloon-request"`)

```json
{
  "project": "checkout",
  "client": "explorer-fernreisen",
  "supplier": "ratehawk",
  "api": "accommodations",
  "action": "search",
  "http": {
    "method": "POST",
    "url": "https://api.ratehawk.com/v1/accommodations/search",
    "queries": { "lang": "de", "api_key": "[REDACTED]" },
    "headers": {
      "Authorization": "[REDACTED]",
      "Accept": "application/json",
      "Content-Type": "application/json"
    },
    "body": {
      "destination": "Mallorca",
      "guests": [{ "name": "Jane" }],
      "payment": { "cardNumber": "[REDACTED]", "cvv": "[REDACTED]", "holder": "Jane Doe" }
    }
  },
  "correlation_id": "21200c57ff98fa8e",
  "saloon": {
    "connector": "App\\Http\\Integrations\\Ratehawk\\RatehawkConnector",
    "request": "App\\Http\\Integrations\\Ratehawk\\Requests\\SearchAccommodationsRequest"
  }
}
```

**Response** (`message: "saloon-response"`)

```json
{
  "project": "checkout",
  "client": "explorer-fernreisen",
  "supplier": "ratehawk",
  "api": "accommodations",
  "action": "search",
  "http": {
    "method": "POST",
    "url": "https://api.ratehawk.com/v1/accommodations/search",
    "status": 200,
    "headers": { "Content-Type": "application/json", "Date": "Wed, 23 Sep 2026 15:01:16 GMT" },
    "body": {
      "notifications": [],
      "offers": [{ "id": "H1", "price": 99.5 }],
      "paging": { "page": 1, "size": 6, "total": 6 }
    }
  },
  "response_time_in_seconds": 2.16,
  "correlation_id": "21200c57ff98fa8e",
  "saloon": { "connector": "…", "request": "…" }
}
```

To find both entries of one call in Cloud Logging:

```
jsonPayload.context.correlation_id="21200c57ff98fa8e"
```

### Fields set by the package

| Field | In | Description |
|---|---|---|
| `http.method`, `http.url` | all | HTTP method and URL without query string or credentials |
| `http.queries` | request | Query parameters (redacted) |
| `http.headers` | request, response | Headers (redacted). Single values are collapsed to strings |
| `http.body` | request, response | Body; see [How bodies are logged](#how-bodies-are-logged) |
| `http.status` | response | HTTP status code |
| `response_time_in_seconds` | response, failure | Time between sending and receiving |
| `correlation_id` | all | Random id shared by the entries of one exchange |
| `saloon.connector`, `saloon.request` | all | Fully qualified class names |
| `error`, `exception` | failure | Error type/message/code and the original exception |
| `mocked`, `cached` | response | Present (`true`) only for `MockClient` / cached responses |

These keys take precedence over your own context keys of the same name.

## Adding context

Implement `ProvidesLogContext` on the connector and/or the request to add your own fields to every entry:

```php
use Milzer\SaloonLogger\Contracts\ProvidesLogContext;
use Milzer\SaloonLogger\Plugins\HasLogging;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

class RatehawkConnector extends Connector implements ProvidesLogContext
{
    use HasLogging;

    public function __construct(private readonly string $client) {}

    public function logContext(PendingRequest $pendingRequest): array
    {
        return [
            'supplier' => 'ratehawk',
            'client' => $this->client,
        ];
    }
}
```

```php
use Milzer\SaloonLogger\Contracts\ProvidesLogContext;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;

class SearchAccommodationsRequest extends Request implements ProvidesLogContext
{
    public function logContext(PendingRequest $pendingRequest): array
    {
        return ['api' => 'accommodations', 'action' => 'search'];
    }
}
```

Context is merged in this order, with later sources winning:

1. `context` from the config (static, e.g. `['project' => 'checkout']`)
2. the connector's `logContext()`
3. the request's `logContext()`

`logContext()` runs once the PendingRequest is fully built, so you can read the final headers, query and body from `$pendingRequest`.

## Configuration

All options live in `config/saloon-logger.php` (Laravel), or in the `LoggingOptions` constructor (plain PHP).

| Config key | `LoggingOptions` argument | Default | Description |
|---|---|---|---|
| `enabled` | `enabled` | `true` | Master switch (`SALOON_LOGGER_ENABLED`) |
| `channel` | – | `null` | Laravel log channel, `null` = default (`SALOON_LOGGER_CHANNEL`) |
| `messages.request` | `requestMessage` | `saloon-request` | `SALOON_LOGGER_REQUEST_MESSAGE` |
| `messages.response` | `responseMessage` | `saloon-response` | `SALOON_LOGGER_RESPONSE_MESSAGE` |
| `messages.failure` | `failureMessage` | `saloon-failure` | `SALOON_LOGGER_FAILURE_MESSAGE` |
| `levels.request` | `requestLevel` | `info` | |
| `levels.response` | `responseLevel` | `info` | 1xx–3xx |
| `levels.client_error` | `clientErrorLevel` | `warning` | 4xx |
| `levels.server_error` | `serverErrorLevel` | `error` | 5xx |
| `levels.failure` | `failureLevel` | `error` | Connection errors, timeouts |
| `log.requests` | `logRequests` | `true` | Log request entries |
| `log.responses` | `logResponses` | `true` | Log response entries |
| `log.failures` | `logFailures` | `true` | Log fatal failures |
| `log.headers` | `logHeaders` | `true` | Include headers |
| `log.request_body` | `logRequestBody` | `true` | Include request bodies |
| `log.response_body` | `logResponseBody` | `true` | Include response bodies |
| `redact.headers` | `redactHeaders` | see [Redaction](#redaction) | Header names to mask |
| `redact.keys` | `redactKeys` | see [Redaction](#redaction) | Query/body keys to mask |
| `redact.mask` | `redactionMask` | `[REDACTED]` | Replacement value |
| `limits.max_body_bytes` | `maxBodyBytes` | `131072` (128 KB) | Truncate larger bodies; `null` = never |
| `limits.max_parse_bytes` | `maxParseBytes` | `5242880` (5 MB) | Don't read larger bodies at all |
| `context` | `context` | `[]` | Static context for every entry |
| `body_formatters` | `bodyFormatters` | `[]` | Extra [body formatters](#custom-body-formatters) |
| `throw_on_error` | `throwOnError` | `false` | Rethrow logger errors (`SALOON_LOGGER_THROW_ON_ERROR`) |

## Customising per connector or request

Implement `ConfiguresLogging` to adjust the options for one connector or one request. `LoggingOptions` is immutable, so every method returns a new copy:

```php
use Illuminate\Support\Facades\Log;
use Milzer\SaloonLogger\Contracts\ConfiguresLogging;
use Milzer\SaloonLogger\LoggingOptions;
use Saloon\Http\PendingRequest;

class RatehawkConnector extends Connector implements ConfiguresLogging
{
    use HasLogging;

    public function configureLogging(LoggingOptions $options, PendingRequest $pendingRequest): LoggingOptions
    {
        return $options
            ->withLogger(Log::channel('suppliers'))   // send this supplier's logs elsewhere
            ->redactKeys('passport_number', 'date_of_birth')
            ->redactHeaders('X-Signature');
    }
}
```

The connector is asked first, and the request receives the connector's result. That makes it easy to handle single requests differently:

```php
class DownloadVoucherRequest extends Request implements ConfiguresLogging
{
    public function configureLogging(LoggingOptions $options, PendingRequest $pendingRequest): LoggingOptions
    {
        return $options->withoutResponseBody();   // the PDF itself isn't interesting
    }
}

class CreatePaymentRequest extends Request implements ConfiguresLogging
{
    public function configureLogging(LoggingOptions $options, PendingRequest $pendingRequest): LoggingOptions
    {
        return $options->withoutRequestBody();    // never log card payloads, whatever their field names
    }
}
```

Available methods:

| Method | Effect |
|---|---|
| `disable()` | Don't log this connector/request at all |
| `withLogger(LoggerInterface $logger)` | Use another logger/channel |
| `withMessages(?string $request, ?string $response, ?string $failure)` | Change messages (only the ones you pass) |
| `withContext(array $context)` | Merge extra static context |
| `redactKeys(string ...$keys)` | Add body/query keys to mask |
| `redactHeaders(string ...$headers)` | Add headers to mask |
| `withoutHeaders()` | Skip headers |
| `withoutBodies()` / `withoutRequestBody()` / `withoutResponseBody()` | Skip bodies |
| `withMaxBodyBytes(?int $bytes)` | Change truncation limit (`null` = never truncate) |
| `withBodyFormatter(BodyFormatter $formatter)` | Add a custom formatter |
| `with(...)` | Change any option by name, e.g. `->with(logHeaders: false, requestLevel: 'debug')` |

## Log messages and levels

| Event | Default message | Default level |
|---|---|---|
| Request (logged after all middleware and authenticators) | `saloon-request` | `info` |
| Response 1xx–3xx | `saloon-response` | `info` |
| Response 4xx | `saloon-response` | `warning` |
| Response 5xx | `saloon-response` | `error` |
| Fatal failure (timeout, DNS, TLS, connection refused) | `saloon-failure` | `error` |

The defaults work out of the box. If you ever need different names, you have three options, from broadest to narrowest scope:

**1. Environment variables** (Laravel, no config publishing needed):

```dotenv
SALOON_LOGGER_REQUEST_MESSAGE=checkout-to-{supplier}
SALOON_LOGGER_RESPONSE_MESSAGE={supplier}-to-checkout
SALOON_LOGGER_FAILURE_MESSAGE={supplier}-failed
```

**2. The published config file** (`messages.*`).

**3. Per connector or request**, in `configureLogging()`:

```php
return $options->withMessages(
    request: 'checkout-to-{supplier}',
    response: '{supplier}-to-checkout',
);
```

Available placeholders:

| Placeholder | Value |
|---|---|
| `{connector}` | Connector class basename, e.g. `RatehawkConnector` |
| `{request}` | Request class basename, e.g. `SearchAccommodationsRequest` |
| `{method}` | HTTP method |
| `{url}` | URL without query string |
| `{status}` | Status code (responses only) |
| `{anyContextKey}` | Any scalar top-level context value, e.g. `{supplier}` |

Unknown placeholders are left as-is, so a typo is easy to spot.

## How bodies are logged

The `Content-Type` header decides how a body is represented. Detection is lenient: JSON sent as `text/html`, or without any content type, is still decoded.

| Content | Logged as |
|---|---|
| `application/json`, `*+json` (e.g. `problem+json`) | Decoded array, so fields are searchable in your log viewer |
| Malformed JSON | Raw string (still redacted) |
| `application/xml`, `text/xml`, `*+xml` (SOAP) | String, with sensitive elements and attributes masked |
| `application/x-www-form-urlencoded` | Parsed into fields |
| Multipart request | One item per part: text fields shown, files described (`[file omitted: 1.2 MB]`) |
| Text, HTML, CSV, … | String |
| PDF, images, audio/video, archives, `application/octet-stream`, `application/vnd.*`, invalid UTF-8 | Description, e.g. `[binary body omitted: application/pdf, 84 KB]` |
| Non-seekable stream (e.g. `'stream' => true` downloads) | `[body omitted: non-seekable stream]`. The stream is never read |
| Empty body | `null` |

Streams are rewound to their **original position** after reading, so your code and Saloon always get the full body (`$response->json()` works as usual).

## Redaction

Sensitive values are replaced with `[REDACTED]` in:

- **Headers:** `authorization`, `proxy-authorization`, `cookie`, `set-cookie`, `x-api-key`, `api-key`, `x-auth-token`, `x-access-token`, `x-csrf-token`, `x-xsrf-token`
- **Query parameters and bodies:** `*password*`, `*secret*`, `*token*`, `api_key`, `apikey`, `private_key`, `authorization`, `card_number`, `cardnumber`, `pan`, `cvv`, `cvv2`, `cvc`, `cvc2`, `security_code`, `iban`
- **URL credentials:** `https://user:pass@host/…` is logged as `https://host/…`

Matching rules:

- **Case and separators are ignored:** `card_number` matches `cardNumber`, `CardNumber`, `card-number` and `CARD_NUMBER`.
- **`*` is a wildcard:** `*token*` matches `access_token`, `refreshToken` and `X-Session-Token`.
- If a key matches, its whole value is masked, including nested arrays.

Where redaction applies:

| Body type | What is masked |
|---|---|
| JSON / form / query (decoded) | Matching keys at any depth |
| Multipart | Parts with a matching field name |
| Raw XML / SOAP | `<Password>…</Password>`, `<ns:Password>…</ns:Password>`, CDATA contents, `password="…"` attributes |
| Raw strings (malformed JSON, text) | `"key": value` pairs and `key=value` pairs |

Extend the defaults rather than replacing them:

```php
// config/saloon-logger.php
use Milzer\SaloonLogger\LoggingOptions;

'redact' => [
    'headers' => [...LoggingOptions::DEFAULT_REDACTED_HEADERS, 'x-signature'],
    'keys' => [...LoggingOptions::DEFAULT_REDACTED_KEYS, 'passport_number', 'date_of_birth'],
    'mask' => '[REDACTED]',
],
```

> [!IMPORTANT]
> **Payment data / PCI:** redaction is a safety net, not a guarantee. It matches field *names*. A supplier that sends card data under a generic name (for example `<Number>` inside `<CreditCard>`) needs an explicit `redactKeys('number')` on that connector. Simpler and safer: use `withoutRequestBody()` on payment requests.

## Size limits

| Option | Default | Behaviour |
|---|---|---|
| `max_body_bytes` | 128 KB | Larger bodies are **redacted first, then truncated** and end with `... [truncated, 128 KB of 1.4 MB shown]`. A truncated structured body becomes a string. `null` disables truncation. |
| `max_parse_bytes` | 5 MB | Larger bodies are not read at all, to protect memory: `[body omitted: 12 MB larger than the 5 MB parse limit, application/json]` |

> [!TIP]
> Google Cloud Logging rejects entries larger than **256 KB**. Keep `max_body_bytes` well below that, because headers and context take space too.

## Failures, retries, async and pools

| Scenario | Behaviour |
|---|---|
| Connection error, timeout, DNS/TLS failure | A `saloon-failure` entry with `error.type`, `error.message`, `error.code` and the original exception under `exception` (Laravel/Monolog render its stack trace). Your code still gets the `FatalRequestException` as usual. |
| 4xx / 5xx response | Normal response entry at `warning` / `error` level, logged *before* `AlwaysThrowOnErrors` or `$response->throw()` fires |
| Retries (`$tries`) | Every attempt is logged as its own exchange with its own `correlation_id` |
| `sendAsync()` / `pool()` | Requests and responses are logged. See [Known limitations](#known-limitations) for failures |
| `MockClient` | Logged normally and flagged `mocked: true` |
| Plugin on both connector *and* request | Logged once |

**Logging never breaks your HTTP call.** If anything inside the logger throws (including your own `logContext()` or `configureLogging()`), the request continues and this is logged instead:

```
saloon-logger failed to write a log entry   (with the exception)
```

## Testing your application

Saloon's `MockClient` works as usual, and mocked exchanges are logged with `mocked: true`.

To surface logger errors in your test suite instead of swallowing them:

```dotenv
# .env.testing
SALOON_LOGGER_THROW_ON_ERROR=true
```

To assert that something was logged in a Laravel test:

```php
use Illuminate\Support\Facades\Log;

Log::spy();

$connector->withMockClient(new MockClient([MockResponse::make(['ok' => true])]))
    ->send(new SearchAccommodationsRequest);

Log::shouldHaveReceived('log')->withArgs(
    fn (string $level, string $message, array $context) => $message === 'saloon-response'
        && $context['http']['status'] === 200
);
```

To silence logging entirely in tests:

```dotenv
SALOON_LOGGER_ENABLED=false
```

## Custom body formatters

Implement `BodyFormatter` to support a content type the package doesn't know. Custom formatters are tried **before** the built-in ones, and redaction and truncation are applied to whatever they return:

```php
use Milzer\SaloonLogger\Contracts\BodyFormatter;

final class MsgPackFormatter implements BodyFormatter
{
    public function supports(string $mimeType, string $body): bool
    {
        return $mimeType === 'application/msgpack';
    }

    public function format(string $body, string $mimeType): array
    {
        return msgpack_unpack($body);
    }
}
```

Register it globally in the config (resolved from the Laravel container):

```php
'body_formatters' => [App\Logging\MsgPackFormatter::class],
```

or per connector/request:

```php
return $options->withBodyFormatter(new MsgPackFormatter);
```

`$mimeType` is the lower-cased media type without parameters (`application/json; charset=utf-8` becomes `application/json`), or an empty string when there is no `Content-Type`.

## How it works

```mermaid
sequenceDiagram
    participant App
    participant Saloon
    participant Plugin as HasLogging
    participant Logger as PSR-3 logger
    participant API as Supplier API

    App->>Saloon: $connector->send($request)
    Saloon->>Plugin: bootHasLogging($pendingRequest)
    Plugin->>Saloon: register request / response / fatal middleware
    Note over Saloon: auth, headers, body, other middleware
    Saloon->>Plugin: request middleware (runs LAST)
    Plugin->>Logger: saloon-request (final headers & body, redacted)
    Saloon->>API: HTTP request
    alt response received
        API-->>Saloon: HTTP response
        Saloon->>Plugin: response middleware (runs FIRST)
        Plugin->>Logger: saloon-response (status, body, response time)
        Saloon-->>App: Response
    else connection failure
        Saloon->>Plugin: fatal middleware
        Plugin->>Logger: saloon-failure (error + exception)
        Saloon-->>App: throws FatalRequestException
    end
```

Design notes:

- The request is logged by middleware that runs **last**, so it shows what was actually sent, including headers added by authenticators and other middleware.
- The response is logged by middleware that runs **first**, so you get the raw response and an accurate duration before other middleware changes anything.
- Following the [Saloon plugin guidelines](https://docs.saloon.dev/installable-plugins/building-your-own-plugins), the plugin never mutates the connector or request.
- Middleware closures are static and hold no reference to the connector or request, so long-running workers (Octane, Horizon, queues) don't leak memory.
- Each PendingRequest gets its own state object, so async, pooled and retried requests never mix up timers or correlation ids.

### Package structure

```
src/
├── Plugins/HasLogging.php            the Saloon plugin (trait)
├── SaloonLogger.php                  entry point; registers middleware
├── LoggingOptions.php                immutable configuration
├── Contracts/
│   ├── ProvidesLogContext.php        add context from a connector/request
│   ├── ConfiguresLogging.php         override options per connector/request
│   └── BodyFormatter.php             custom body formatting
├── Internal/ExchangeLogger.php       per-request state: timing, correlation, writing
├── Serialization/
│   ├── MessageSerializer.php         builds the "http" section
│   ├── BodySerializer.php            read → format → redact → truncate
│   └── Formatters/                   Binary, Json, Form, Xml, Text
├── Redaction/Redactor.php            header/array/raw-string masking
├── Support/MimeType.php
├── Exceptions/MissingLoggerException.php
└── Laravel/SaloonLoggerServiceProvider.php
config/saloon-logger.php
```

## Known limitations

- **Async connection failures are not logged.** Saloon only runs its fatal-exception pipeline for synchronous requests. For `sendAsync()` and pools, handle failures in the promise's `otherwise()` or the pool's exception handler. Successful and error *responses* are logged normally.
- **Headers added by the HTTP client itself** (e.g. Guzzle's `User-Agent`, `Content-Length`, `Host`) are not part of the request entry, because they're added after Saloon's middleware has run.
- **Redaction matches field names, not values.** See the PCI note under [Redaction](#redaction).

## Development

```bash
git clone https://github.com/milzer/saloon-logger.git
cd saloon-logger
composer install
```

| Command | Runs |
|---|---|
| `composer test` | Pest test suite |
| `composer analyse` | PHPStan at level `max` |
| `composer format` | Laravel Pint (fixes code style) |
| `composer check` | Pint (check only), PHPStan and tests. Run this before pushing |

GitHub Actions runs the tests on PHP 8.2–8.4 against Saloon v3 and v4, with both the lowest and latest dependency versions, plus Pint and PHPStan.

## Releasing a new version

Composer reads versions from Git tags, following [Semantic Versioning](https://semver.org):

1. Update `CHANGELOG.md`.
2. Commit, then tag and push:

```bash
git tag v1.0.0
```

```bash
git push origin v1.0.0
```

3. Optionally, create a GitHub Release from the tag with the changelog entry.

Applications then pick it up with `composer update milzer/saloon-logger`.

| Change | Bump |
|---|---|
| Bug fix, no behaviour change | patch (`1.0.1`) |
| New option or feature, backwards compatible | minor (`1.1.0`) |
| Changed log structure, removed option, raised PHP/Saloon minimum | major (`2.0.0`) |

> [!NOTE]
> The structure of log entries is part of the public API: dashboards, alerts and log-based metrics depend on field names. Renaming a field is a **major** change.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
