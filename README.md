# Saloon Logger

[![tests](https://github.com/milzer/saloon-logger/actions/workflows/tests.yml/badge.svg)](https://github.com/milzer/saloon-logger/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/php-8.2%2B-777bb4)
![Saloon](https://img.shields.io/badge/saloon-v3.10%2B%20%7C%20v4-orange)
![License](https://img.shields.io/badge/license-MIT-green)

A [Saloon](https://docs.saloon.dev) plugin that automatically logs every HTTP request your application sends and every response it receives. The log entries are structured, secrets are masked, and they work with any PSR-3 logger (Laravel, Monolog, …).

```php
class RatehawkConnector extends Connector
{
    use HasLogging;   // ← that's all
}
```

---

## Table of contents

- [What this package provides](#what-this-package-provides)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [The default log entries](#the-default-log-entries)
- [Adding your own properties](#adding-your-own-properties)
- [Masking secrets](#masking-secrets)
- [Large bodies: what happens](#large-bodies-what-happens)
- [How each body type is logged](#how-each-body-type-is-logged)
- [Configuration](#configuration)
- [Customising per connector or request](#customising-per-connector-or-request)
- [Log messages and levels](#log-messages-and-levels)
- [Failures, retries, async and pools](#failures-retries-async-and-pools)
- [Testing your application](#testing-your-application)
- [Custom body formatters](#custom-body-formatters)
- [How it works](#how-it-works)
- [Known limitations](#known-limitations)
- [Development](#development)
- [Releasing a new version](#releasing-a-new-version)
- [License](#license)

---

## What this package provides

Add `use HasLogging;` to a Saloon connector (or to a single request), and every HTTP call it makes writes:

| # | Entry | When |
|---|---|---|
| 1 | **Request entry** | Right before the request is sent. Authentication and all middleware have already run, so it shows exactly what goes out |
| 2 | **Response entry** | When the response arrives, including the response time |
| 3 | **Failure entry** | Instead of #2 when no response arrives (timeout, DNS, TLS, connection refused) |

Along the way the package:

- **Masks secrets** such as tokens, passwords, API keys and card data in headers, query parameters and bodies. See [Masking secrets](#masking-secrets).
- **Understands every body type.** JSON is decoded into searchable fields, XML stays readable, form data is parsed, and files and binary data are replaced by a short description. See [How each body type is logged](#how-each-body-type-is-logged).
- **Protects your logs and memory.** Huge bodies are cut or skipped instead of crashing the logger or your log backend. See [Large bodies](#large-bodies-what-happens).
- **Links request and response** through a shared `correlation_id`.
- **Lets you add your own properties** (`supplier`, `client`, `action`, …). See [Adding your own properties](#adding-your-own-properties).
- **Stays out of your way.** Your code can still read the response body, and a problem inside the logger never breaks the HTTP call.

It works with Saloon v3.10+ and v4, sync and async requests, pools, retries and `MockClient`, in Laravel or any other framework.

## Requirements

| | Version |
|---|---|
| PHP | 8.2 or higher |
| Saloon | `^3.10` or `^4.0` |
| Logger | Any PSR-3 logger |
| Laravel (optional) | 11 or 12 |

## Installation

The package is installed from GitHub. Add the repository to your application's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/milzer/saloon-logger" }
    ]
}
```

Then install it:

```bash
composer require milzer/saloon-logger
```

Composer installs the latest tagged release. To use the unreleased `main` branch, require `milzer/saloon-logger:dev-main`.

### If the repository is private

Composer needs a GitHub token with read access:

1. Create a [fine-grained personal access token](https://github.com/settings/personal-access-tokens) with **Contents: Read-only** on this repository.
2. Register it with Composer on your machine:

```bash
composer config --global github-oauth.github.com <your-token>
```

In CI, set the `COMPOSER_AUTH` environment variable instead:

```bash
COMPOSER_AUTH='{"github-oauth":{"github.com":"<your-token>"}}'
```

Never commit the token or an `auth.json` file.

## Quick start

### Laravel

The service provider is auto-discovered, so you only add the plugin:

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

Entries go to your default log channel. To use another channel, set `SALOON_LOGGER_CHANNEL=suppliers` in `.env`.

To change other defaults, publish the config file:

```bash
php artisan vendor:publish --tag=saloon-logger-config
```

### Plain PHP / other frameworks

Register a default logger once during bootstrap, then use the trait the same way:

```php
use Milzer\SaloonLogger\SaloonLogger;

SaloonLogger::setDefault(new SaloonLogger($psrLogger));   // any Psr\Log\LoggerInterface
```

---

## The default log entries

This is what you get **out of the box**, with no extra configuration. The example is a booking request to a supplier.

Every entry is a normal PSR-3 call: `$logger->log($level, $message, $context)`. The blocks below show the **context**. In Google Cloud Logging, written through Laravel's JSON formatter, it appears as `jsonPayload.context`.

### Request entry

`level: info` · `message: "saloon-request"`

```json
{
  "http": {
    "method": "POST",
    "url": "https://api.supplier.com/v1/bookings",
    "queries": {
      "lang": "de",
      "api_key": "[REDACTED]"
    },
    "headers": {
      "Content-Type": "application/json",
      "Accept": "application/json",
      "X-Api-Key": "[REDACTED]",
      "Authorization": "[REDACTED]"
    },
    "body": {
      "hotel_id": "H1",
      "guest": { "name": "Jane Doe", "email": "jane@example.com" },
      "payment": { "cardNumber": "[REDACTED]", "expiry": "12/29", "cvv": "[REDACTED]" }
    }
  },
  "correlation_id": "a0b039ed3d0677b5",
  "saloon": {
    "connector": "App\\Http\\Integrations\\Supplier\\SupplierConnector",
    "request": "App\\Http\\Integrations\\Supplier\\Requests\\CreateBookingRequest"
  }
}
```

### Response entry

`level: info` (1xx–3xx), `warning` (4xx) or `error` (5xx) · `message: "saloon-response"`

```json
{
  "http": {
    "method": "POST",
    "url": "https://api.supplier.com/v1/bookings",
    "status": 201,
    "headers": {
      "Content-Type": "application/json",
      "Date": "Wed, 23 Sep 2026 15:01:16 GMT"
    },
    "body": {
      "booking_id": "B-123",
      "status": "confirmed",
      "access_token": "[REDACTED]"
    }
  },
  "response_time_in_seconds": 2.16,
  "correlation_id": "a0b039ed3d0677b5",
  "saloon": {
    "connector": "App\\Http\\Integrations\\Supplier\\SupplierConnector",
    "request": "App\\Http\\Integrations\\Supplier\\Requests\\CreateBookingRequest"
  }
}
```

The request and response share the same `correlation_id`. To see both entries of one call in Cloud Logging:

```
jsonPayload.context.correlation_id="a0b039ed3d0677b5"
```

### Failure entry

`level: error` · `message: "saloon-failure"`. It replaces the response entry when no response arrives.

```json
{
  "http": {
    "method": "POST",
    "url": "https://api.supplier.com/v1/bookings"
  },
  "response_time_in_seconds": 30.004,
  "error": {
    "type": "GuzzleHttp\\Exception\\ConnectException",
    "message": "cURL error 28: Operation timed out after 30001 milliseconds",
    "code": 0
  },
  "exception": "(the original exception object; Laravel/Monolog render it with its stack trace)",
  "correlation_id": "a0b039ed3d0677b5",
  "saloon": { "connector": "…", "request": "…" }
}
```

### Default properties at a glance

| Property | Request | Response | Failure | Description |
|---|:-:|:-:|:-:|---|
| `http.method` | ✓ | ✓ | ✓ | HTTP method |
| `http.url` | ✓ | ✓ | ✓ | URL without query string and without `user:pass@` |
| `http.queries` | ✓ | | | Query parameters, masked |
| `http.headers` | ✓ | ✓ | | Headers, masked. A single value is shown as a string |
| `http.body` | ✓ | ✓ | | Body. See [How each body type is logged](#how-each-body-type-is-logged) |
| `http.status` | | ✓ | | Status code |
| `response_time_in_seconds` | | ✓ | ✓ | Duration, 3 decimals |
| `error.type` / `.message` / `.code` | | | ✓ | What went wrong |
| `exception` | | | ✓ | Original exception, for stack traces and error reporting |
| `correlation_id` | ✓ | ✓ | ✓ | Random id shared by the entries of one call |
| `saloon.connector` / `.request` | ✓ | ✓ | ✓ | Classes that made the call |
| `mocked` | | only if true | | Response came from Saloon's `MockClient` |
| `cached` | | only if true | | Response came from Saloon's cache plugin |

You can switch off headers or bodies (see [Configuration](#configuration)). The other properties are always present.

---

## Adding your own properties

The default entries describe the HTTP call. Usually you also want **business context**: which supplier, which client, which API and action. You can add any properties you need, and they appear next to `http` in every entry.

### Option 1: per connector and/or request (dynamic)

Implement `ProvidesLogContext` and return an array:

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
            'client' => $this->client,          // e.g. "explorer-fernreisen"
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
    public function __construct(private readonly string $bookingReference) {}

    public function logContext(PendingRequest $pendingRequest): array
    {
        return [
            'api' => 'accommodations',
            'action' => 'search',
            'booking_reference' => $this->bookingReference,
        ];
    }
}
```

### Option 2: static properties for every entry (config)

```php
// config/saloon-logger.php
'context' => [
    'project' => 'checkout',
],
```

### Result

Your properties are added to every entry: request, response and failure.

```json
{
  "project": "checkout",
  "supplier": "ratehawk",
  "client": "explorer-fernreisen",
  "api": "accommodations",
  "action": "search",
  "booking_reference": "BK-2026-0042",
  "http": { "method": "POST", "url": "…", "status": 200, "headers": { … }, "body": { … } },
  "response_time_in_seconds": 2.16,
  "correlation_id": "a0b039ed3d0677b5",
  "saloon": { … }
}
```

### Rules

- **Merge order:** config `context` → connector `logContext()` → request `logContext()`. Later sources win on the same key, and nested arrays are merged.
- **Values:** anything JSON-serialisable (strings, numbers, booleans, arrays).
- **Reserved names:** `http`, `response_time_in_seconds`, `correlation_id`, `saloon`, `error`, `exception`, `mocked` and `cached` are set by the package and always win. Pick different names for your own properties.
- **Timing:** `logContext()` runs once the request is fully built, so you can read the final headers, query or body from `$pendingRequest`.
- **Messages:** your scalar properties can be used as placeholders in messages, e.g. `checkout-to-{supplier}`. See [Log messages and levels](#log-messages-and-levels).

---

## Masking secrets

Logs are read by many people and kept for a long time, so secrets must never end up in them. Before anything is written, the package replaces the **value** of every sensitive field with `[REDACTED]`. The field itself stays visible, so you can still tell that it was sent.

### Example: what is sent vs. what is logged

Your application sends this request:

```http
POST https://api.supplier.com/v1/bookings?lang=de&api_key=sk_live_123
Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.secret
X-Api-Key: sk_live_123
Accept: application/json
Content-Type: application/json

{
  "hotel_id": "H1",
  "guest":   { "name": "Jane Doe", "email": "jane@example.com" },
  "payment": { "cardNumber": "4111111111111111", "expiry": "12/29", "cvv": "123" },
  "user":    { "login": "jane", "password": "hunter2" }
}
```

The log entry contains:

```json
{
  "http": {
    "method": "POST",
    "url": "https://api.supplier.com/v1/bookings",
    "queries": { "lang": "de", "api_key": "[REDACTED]" },
    "headers": {
      "Content-Type": "application/json",
      "Accept": "application/json",
      "X-Api-Key": "[REDACTED]",
      "Authorization": "[REDACTED]"
    },
    "body": {
      "hotel_id": "H1",
      "guest":   { "name": "Jane Doe", "email": "jane@example.com" },
      "payment": { "cardNumber": "[REDACTED]", "expiry": "12/29", "cvv": "[REDACTED]" },
      "user":    { "login": "jane", "password": "[REDACTED]" }
    }
  }
}
```

The same applies to the response. A body like `{"booking_id": "B-123", "access_token": "tok_abc"}` is logged as `{"booking_id": "B-123", "access_token": "[REDACTED]"}`.

It also works for **XML / SOAP**. This body:

```xml
<Envelope><Auth user="jane" password="hunter2"/><CardNumber>4111111111111111</CardNumber><Hotel>H1</Hotel></Envelope>
```

is logged as:

```xml
<Envelope><Auth user="jane" password="[REDACTED]"/><CardNumber>[REDACTED]</CardNumber><Hotel>H1</Hotel></Envelope>
```

It also covers **form data** (`client_secret=shh` → `client_secret=[REDACTED]`), **multipart** fields, and **credentials in the URL** (`https://user:pass@api.supplier.com` is logged as `https://api.supplier.com`).

### What is masked by default

| Where | Names |
|---|---|
| Headers | `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `Api-Key`, `X-Auth-Token`, `X-Access-Token`, `X-Csrf-Token`, `X-Xsrf-Token` |
| Query parameters and body fields | `*password*`, `*secret*`, `*token*`, `api_key`, `apikey`, `private_key`, `authorization`, `card_number`, `cardnumber`, `pan`, `cvv`, `cvv2`, `cvc`, `cvc2`, `security_code`, `iban` |

### How names are matched

- **Case and separators don't matter.** The rule `card_number` matches `card_number`, `cardNumber`, `CardNumber`, `card-number` and `CARD_NUMBER`.
- **`*` is a wildcard.** `*token*` matches `token`, `access_token`, `refreshToken` and `X-Session-Token`. `*password*` matches `password`, `new_password` and `passwordConfirmation`.
- **At any depth.** Fields are found no matter how deeply they are nested in JSON, and in every item of a list.
- **Whole value.** If a matching field contains an object, the whole object is replaced.

### Adding your own names

Globally, in the config. Extend the defaults rather than replacing them:

```php
// config/saloon-logger.php
use Milzer\SaloonLogger\LoggingOptions;

'redact' => [
    'headers' => [...LoggingOptions::DEFAULT_REDACTED_HEADERS, 'x-signature'],
    'keys'    => [...LoggingOptions::DEFAULT_REDACTED_KEYS, 'passport_number', 'date_of_birth'],
    'mask'    => '[REDACTED]',
],
```

Or for one connector or request only:

```php
public function configureLogging(LoggingOptions $options, PendingRequest $pendingRequest): LoggingOptions
{
    return $options->redactKeys('passport_number')->redactHeaders('X-Signature');
}
```

> [!IMPORTANT]
> **Payment data / PCI:** masking works on field *names*. If a supplier sends card data under a generic name (for example `<Number>` inside `<CreditCard>`), add that name explicitly with `redactKeys('number')`. The safest option for payment requests is not to log the body at all: `$options->withoutRequestBody()`.

---

## Large bodies: what happens

Supplier responses can be huge (a hotel search with thousands of offers), and some responses are files (PDF vouchers). Logging them unchanged would cause two problems:

1. **Your log backend rejects the entry.** Google Cloud Logging, for example, refuses entries larger than **256 KB**, so you would lose the log line completely.
2. **Your application uses too much memory** reading, decoding and encoding a body of many megabytes, just to log it.

Two limits prevent this:

| Setting | Default | Meaning |
|---|---|---|
| `max_body_bytes` | **128 KB** | Largest body that is logged in full |
| `max_parse_bytes` | **5 MB** | Largest body that is read at all |

A body falls into one of three zones:

| Body size | What happens | What you see in `http.body` |
|---|---|---|
| **Up to 128 KB** | Read, decoded, masked and logged in full | The normal body, e.g. a JSON object |
| **128 KB – 5 MB** | Read, decoded and **masked first**, then **cut to 128 KB** | A string ending in `... [truncated, 128 KB of 1.4 MB shown]` |
| **Over 5 MB** | **Not read at all** | `[body omitted: 12 MB larger than the 5 MB parse limit, application/json]` |

### Zone 1: normal body (up to 128 KB)

Logged as usual:

```json
"body": { "offers": [ { "id": "H1", "name": "Hotel Palma", "price": 99.5 }, … ], "paging": { "page": 1 } }
```

### Zone 2: truncated body (128 KB – 5 MB)

Say a search response is 1.4 MB. The package:

1. reads the body (the stream is rewound afterwards, so your code still gets all of it),
2. decodes the JSON and **masks secrets in the complete body**,
3. encodes it back to a JSON string and keeps the first 128 KB,
4. appends a note saying how much was cut.

```json
"body": "{\"offers\":[{\"id\":\"H1\",\"name\":\"Hotel Palma\",\"price\":99.5},{\"id\":\"H2\",\"name\":\"Hotel Soller\",\"price\":120},{\"id\"... [truncated, 128 KB of 1.4 MB shown]"
```

Good to know:

- Masking happens **before** cutting, so a secret can never slip through by being cut in half, and nothing after the cut is ever written.
- A truncated JSON body becomes a **string**, so its fields are no longer individually searchable in the log viewer. The first 128 KB is still readable.
- Your application is **not affected**. `$response->json()` still returns the full 1.4 MB response. Only the log entry is shortened.

### Zone 3: omitted body (over 5 MB)

The body is never loaded into memory for logging. The entry still has method, URL, status, headers and timing, and the body is replaced by a description:

```json
"body": "[body omitted: 12 MB larger than the 5 MB parse limit, application/json]"
```

### Related cases

| Case | `http.body` |
|---|---|
| Binary content (PDF, image, zip, …) up to 5 MB | `[binary body omitted: application/pdf, 84 KB]`. Larger ones get the zone 3 message |
| Uploaded files in a multipart request | `[file omitted: 1.2 MB]` for that part; text fields are still shown |
| Streamed download (non-seekable stream) | `[body omitted: non-seekable stream]`. Never read, so your download is untouched |

### Changing the limits

In `config/saloon-logger.php`:

```php
'limits' => [
    'max_body_bytes'  => 200 * 1024,        // log up to 200 KB; null = never truncate
    'max_parse_bytes' => 10 * 1024 * 1024,  // read up to 10 MB
],
```

Or per connector or request:

```php
return $options->withMaxBodyBytes(null);     // never truncate for this connector
return $options->withoutResponseBody();      // don't log the response body at all
```

> [!TIP]
> Keep `max_body_bytes` well below your backend's entry limit (256 KB on Google Cloud Logging). Headers, your properties and the rest of the entry need space too.

---

## How each body type is logged

The `Content-Type` header decides how a body is shown. Detection is lenient: JSON sent as `text/html`, or without a content type, is still decoded.

| Content | Shown as |
|---|---|
| `application/json`, `*+json` (e.g. `problem+json`) | Decoded object/array, searchable in your log viewer |
| Malformed JSON | Raw string (still masked) |
| `application/xml`, `text/xml`, `*+xml` (SOAP) | String, with sensitive elements and attributes masked |
| `application/x-www-form-urlencoded` | Parsed into fields |
| Multipart request | One item per part, e.g. `[{"name":"title","contents":"Voucher"},{"name":"file","filename":"voucher.pdf","contents":"[file omitted: 1 KB]"}]` |
| Text, HTML, CSV, … | String |
| PDF, images, audio/video, archives, `application/octet-stream`, `application/vnd.*`, invalid UTF-8 | `[binary body omitted: <type>, <size>]` |
| Non-seekable stream | `[body omitted: non-seekable stream]` |
| Empty body | `null` |

Reading a body for logging never consumes it: streams are rewound to their original position afterwards.

---

## Configuration

In Laravel, all options live in `config/saloon-logger.php`. In plain PHP, pass them to the `LoggingOptions` constructor.

| Config key | `LoggingOptions` argument | Default | Env |
|---|---|---|---|
| `enabled` | `enabled` | `true` | `SALOON_LOGGER_ENABLED` |
| `channel` | – | `null` (default channel) | `SALOON_LOGGER_CHANNEL` |
| `messages.request` | `requestMessage` | `saloon-request` | `SALOON_LOGGER_REQUEST_MESSAGE` |
| `messages.response` | `responseMessage` | `saloon-response` | `SALOON_LOGGER_RESPONSE_MESSAGE` |
| `messages.failure` | `failureMessage` | `saloon-failure` | `SALOON_LOGGER_FAILURE_MESSAGE` |
| `levels.request` | `requestLevel` | `info` | |
| `levels.response` | `responseLevel` | `info` | |
| `levels.client_error` | `clientErrorLevel` | `warning` | |
| `levels.server_error` | `serverErrorLevel` | `error` | |
| `levels.failure` | `failureLevel` | `error` | |
| `log.requests` | `logRequests` | `true` | |
| `log.responses` | `logResponses` | `true` | |
| `log.failures` | `logFailures` | `true` | |
| `log.headers` | `logHeaders` | `true` | |
| `log.request_body` | `logRequestBody` | `true` | |
| `log.response_body` | `logResponseBody` | `true` | |
| `redact.headers` | `redactHeaders` | [see above](#what-is-masked-by-default) | |
| `redact.keys` | `redactKeys` | [see above](#what-is-masked-by-default) | |
| `redact.mask` | `redactionMask` | `[REDACTED]` | |
| `limits.max_body_bytes` | `maxBodyBytes` | `131072` (128 KB) | |
| `limits.max_parse_bytes` | `maxParseBytes` | `5242880` (5 MB) | |
| `context` | `context` | `[]` | |
| `body_formatters` | `bodyFormatters` | `[]` | |
| `throw_on_error` | `throwOnError` | `false` | `SALOON_LOGGER_THROW_ON_ERROR` |

## Customising per connector or request

Implement `ConfiguresLogging` to change options for one connector or one request. The connector is asked first, then the request receives the connector's result. `LoggingOptions` is immutable, so every method returns a new copy.

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
            ->withLogger(Log::channel('suppliers'))
            ->redactKeys('passport_number');
    }
}

class CreatePaymentRequest extends Request implements ConfiguresLogging
{
    public function configureLogging(LoggingOptions $options, PendingRequest $pendingRequest): LoggingOptions
    {
        return $options->withoutRequestBody();
    }
}
```

| Method | Effect |
|---|---|
| `disable()` | Don't log this connector/request |
| `withLogger(LoggerInterface $logger)` | Use another logger or channel |
| `withMessages(?string $request, ?string $response, ?string $failure)` | Change messages (only the ones you pass) |
| `withContext(array $context)` | Add static properties |
| `redactKeys(string ...$keys)` | Mask more body/query fields |
| `redactHeaders(string ...$headers)` | Mask more headers |
| `withoutHeaders()` | Leave out headers |
| `withoutBodies()` / `withoutRequestBody()` / `withoutResponseBody()` | Leave out bodies |
| `withMaxBodyBytes(?int $bytes)` | Change the truncation limit (`null` = never truncate) |
| `withBodyFormatter(BodyFormatter $formatter)` | Add a custom formatter |
| `with(...)` | Change any option by name, e.g. `->with(logHeaders: false, requestLevel: 'debug')` |

## Log messages and levels

| Event | Message | Level |
|---|---|---|
| Request | `saloon-request` | `info` |
| Response 1xx–3xx | `saloon-response` | `info` |
| Response 4xx | `saloon-response` | `warning` |
| Response 5xx | `saloon-response` | `error` |
| Failure (no response) | `saloon-failure` | `error` |

The default message names are recommended. If you ever need different ones, you can change them without touching the package:

```dotenv
# .env (Laravel)
SALOON_LOGGER_REQUEST_MESSAGE=checkout-to-{supplier}
SALOON_LOGGER_RESPONSE_MESSAGE={supplier}-to-checkout
```

You can also set them in the published config (`messages.*`), or per connector/request with `$options->withMessages(request: '…', response: '…')`.

Available placeholders: `{connector}`, `{request}` (class basenames), `{method}`, `{url}`, `{status}` (responses only), and any of [your own scalar properties](#adding-your-own-properties), e.g. `{supplier}`.

## Failures, retries, async and pools

| Scenario | Behaviour |
|---|---|
| Timeout, DNS, TLS, connection refused | `saloon-failure` entry with the error and original exception. Your code still receives the `FatalRequestException` |
| 4xx / 5xx response | Response entry at `warning` / `error`, written *before* `AlwaysThrowOnErrors` or `$response->throw()` throws |
| Retries (`$tries`) | Each attempt is logged separately, with its own `correlation_id` |
| `sendAsync()` / `pool()` | Requests and responses are logged. See [Known limitations](#known-limitations) |
| `MockClient` | Logged normally, with `mocked: true` |
| Plugin on both connector *and* request | Logged once |
| An error inside the logger (or your `logContext()`) | The HTTP call continues. `saloon-logger failed to write a log entry` is logged instead |

## Testing your application

`MockClient` works as usual, and mocked calls are logged with `mocked: true`.

```dotenv
# .env.testing
SALOON_LOGGER_THROW_ON_ERROR=true    # surface logger errors in tests
# SALOON_LOGGER_ENABLED=false        # or silence logging completely
```

To assert that an entry was written in a Laravel test:

```php
use Illuminate\Support\Facades\Log;

Log::spy();

$connector->withMockClient(new MockClient([MockResponse::make(['ok' => true])]))
    ->send(new SearchAccommodationsRequest('BK-1'));

Log::shouldHaveReceived('log')->withArgs(
    fn (string $level, string $message, array $context) => $message === 'saloon-response'
        && $context['http']['status'] === 200
);
```

## Custom body formatters

To support a content type the package doesn't know, implement `BodyFormatter`. Custom formatters run **before** the built-in ones, and masking and size limits still apply to what they return.

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

Register it in the config (`'body_formatters' => [MsgPackFormatter::class]`) or per connector (`$options->withBodyFormatter(new MsgPackFormatter)`).

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
    Plugin->>Saloon: register request / response / failure middleware
    Note over Saloon: auth, headers, body, other middleware
    Saloon->>Plugin: request middleware (runs LAST)
    Plugin->>Logger: saloon-request
    Saloon->>API: HTTP request
    alt response received
        API-->>Saloon: HTTP response
        Saloon->>Plugin: response middleware (runs FIRST)
        Plugin->>Logger: saloon-response
        Saloon-->>App: Response
    else no response
        Saloon->>Plugin: failure middleware
        Plugin->>Logger: saloon-failure
        Saloon-->>App: throws FatalRequestException
    end
```

- The request is logged **last** among request middleware, so it shows what was really sent, including authentication headers.
- The response is logged **first** among response middleware, so you get the raw response and an accurate duration.
- The plugin never changes the connector or request, following the [Saloon plugin guidelines](https://docs.saloon.dev/installable-plugins/building-your-own-plugins).
- Nothing holds a reference to the connector or request, so long-running workers (Octane, Horizon, queues) don't leak memory.

<details>
<summary>Package structure</summary>

```
src/
├── Plugins/HasLogging.php            the Saloon plugin (trait)
├── SaloonLogger.php                  entry point; registers the middleware
├── LoggingOptions.php                immutable configuration
├── Contracts/
│   ├── ProvidesLogContext.php        add your own properties
│   ├── ConfiguresLogging.php         override options per connector/request
│   └── BodyFormatter.php             custom body formatting
├── Internal/ExchangeLogger.php       per-call state: timing, correlation id, writing
├── Serialization/
│   ├── MessageSerializer.php         builds the "http" section
│   ├── BodySerializer.php            read → format → mask → truncate
│   └── Formatters/                   Binary, Json, Form, Xml, Text
├── Redaction/Redactor.php            masking
├── Support/MimeType.php
├── Exceptions/MissingLoggerException.php
└── Laravel/SaloonLoggerServiceProvider.php
config/saloon-logger.php
```

</details>

## Known limitations

- **Connection failures of async requests are not logged.** Saloon only runs its failure pipeline for synchronous requests. For `sendAsync()` and pools, handle failures in `otherwise()` or the pool's exception handler. Their responses are logged normally.
- **Headers added by Guzzle itself** (`User-Agent`, `Content-Length`, `Host`) are not in the request entry, because Guzzle adds them after Saloon's middleware has run.
- **Masking matches field names, not values.** See the payment data note in [Masking secrets](#masking-secrets).

## Development

```bash
git clone https://github.com/milzer/saloon-logger.git
cd saloon-logger
composer install
```

| Command | Runs |
|---|---|
| `composer test` | Pest tests |
| `composer analyse` | PHPStan (level `max`) |
| `composer format` | Laravel Pint (fixes code style) |
| `composer check` | Style check, PHPStan and tests. Run this before pushing |

GitHub Actions runs the tests on PHP 8.2–8.4 against Saloon v3 and v4, with the lowest and the latest dependency versions.

## Releasing a new version

Composer reads versions from Git tags ([Semantic Versioning](https://semver.org)):

1. Update `CHANGELOG.md` and commit.
2. Create and push the tag:

```bash
git tag v1.0.0
```

```bash
git push origin v1.0.0
```

3. Applications update with `composer update milzer/saloon-logger`.

| Change | Version bump |
|---|---|
| Bug fix | patch: `1.0.1` |
| New option or feature, backwards compatible | minor: `1.1.0` |
| Renamed/removed log property or option, higher PHP/Saloon minimum | major: `2.0.0` |

> [!NOTE]
> The log structure is part of the public API, because dashboards, alerts and log-based metrics depend on property names. Renaming a property is a **major** change.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
