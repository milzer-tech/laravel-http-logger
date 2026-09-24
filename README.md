# Laravel HTTP Logger

[![tests](https://github.com/milzer-tech/laravel-http-logger/actions/workflows/tests.yml/badge.svg)](https://github.com/milzer-tech/laravel-http-logger/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/milzer/laravel-http-logger)](https://packagist.org/packages/milzer/laravel-http-logger)
![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012-ff2d20)
![License](https://img.shields.io/badge/license-MIT-green)

Log all HTTP traffic of your Laravel app as structured, redacted log entries:

- **Incoming:** requests to your app and its responses.
- **Outgoing:** calls to external APIs made with [Saloon](https://docs.saloon.dev).

Both directions use the same format and share a **trace id**, so one filter shows a whole flow.

## Features

- Secrets (tokens, passwords, API keys, card data) are masked
- JSON, XML/SOAP, form data, uploads, files and streams are handled
- Large bodies are truncated in the log and optionally stored in full on a disk
- Entries are written **after the response is sent**, so clients don't wait
- Add your own properties (`supplier`, `client`, …)
- Logging errors never break your app

## Installation

```bash
composer require milzer/laravel-http-logger
php artisan vendor:publish --tag=http-logger-config   # optional
```

Requires PHP 8.2+, Laravel 11 or 12, and Saloon 4 for outgoing logging.

## Usage

**Incoming:** add the middleware in `bootstrap/app.php` (or use the `http-logger` alias on routes):

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(\Milzer\HttpLogger\Laravel\Middleware\LogIncomingRequests::class);
})
```

**Outgoing:** add the plugin to a Saloon connector (or a single request):

```php
use Milzer\HttpLogger\Saloon\HasLogging;

class RatehawkConnector extends Connector
{
    use HasLogging;
}
```

## What gets logged

A client calls `POST /api/bookings` and your app calls a supplier while handling it. That produces four entries:

| # | Message | Level |
|---|---|---|
| 1 | `incoming-request` | info |
| 2 | `outgoing-request` | info |
| 3 | `outgoing-response` | info / warning (4xx) / error (5xx) |
| 4 | `incoming-response` | info / warning (4xx) / error (5xx) |

If an outgoing call gets no response (timeout, DNS, connection refused), you get `outgoing-failure` (error) with the exception instead of #3.

Entry #3 looks like this:

```json
{
  "http": {
    "method": "POST",
    "url": "https://api.ratehawk.com/v1/bookings",
    "status": 201,
    "headers": { "Content-Type": "application/json" },
    "body": { "booking_id": "RH-991", "access_token": "[REDACTED]" }
  },
  "response_time_in_seconds": 2.16,
  "direction": "outgoing",
  "correlation_id": "42fa81c88f223f83",
  "trace_id": "4bf92f3577b34da6a3ce929d0e0e4736",
  "occurred_at": "2026-09-24T12:42:56.545585+00:00",
  "saloon": { "connector": "App\\...\\RatehawkConnector", "request": "App\\...\\CreateBookingRequest" }
}
```

- Request entries have `queries` and no `status`. Incoming entries also have `http.route`, `http.ip` and, for logged-in users, `user_id`.
- `correlation_id` links a request to its response. `trace_id` links everything caused by one incoming request, including Saloon calls in dispatched jobs.
- A safe `X-Request-Id` header from the caller is reused as `trace_id`.

Filter in Google Cloud Logging:

```
jsonPayload.context.trace_id="4bf92f3577b34da6a3ce929d0e0e4736"
```

## Adding your own properties

```php
// every entry: config/http-logger.php
'context' => ['project' => 'checkout'],
```

```php
// outgoing: on a Saloon connector or request
class RatehawkConnector extends Connector implements ProvidesLogContext
{
    use HasLogging;

    public function logContext(PendingRequest $pendingRequest): array
    {
        return ['supplier' => 'ratehawk'];
    }
}
```

```php
// incoming: a class registered as 'incoming.context' in the config
class IncomingLogContext implements ProvidesIncomingLogContext
{
    public function incomingLogContext(Request $request): array
    {
        return ['client' => $request->header('X-Client')];
    }
}
```

Result: `{ "project": "checkout", "supplier": "ratehawk", "http": { … }, … }`

## Masking secrets

**Sent:**

```http
POST /v1/bookings?api_key=sk_live_123
Authorization: Bearer eyJhbGciOi...

{ "guest": { "name": "Jane" }, "payment": { "cardNumber": "4111111111111111", "cvv": "123" } }
```

**Logged:**

```json
"queries": { "api_key": "[REDACTED]" },
"headers": { "Authorization": "[REDACTED]" },
"body": { "guest": { "name": "Jane" }, "payment": { "cardNumber": "[REDACTED]", "cvv": "[REDACTED]" } }
```

XML works too: `<Password>hunter2</Password>` → `<Password>[REDACTED]</Password>`.

- **Masked by default:** `Authorization`, `Cookie`, `X-Api-Key` and similar headers, plus fields named `*password*`, `*secret*`, `*token*`, `api_key`, `card_number`, `cvv`, `cvc`, `iban`, …
- **Matching:** names are matched ignoring case and separators (`card_number` = `cardNumber` = `Card-Number`), at any depth.
- **Adding names:**

```php
'redact' => [
    'keys' => [...LoggingOptions::DEFAULT_REDACTED_KEYS, 'passport_number'],
],
```

> [!IMPORTANT]
> Masking matches field *names*. For payment requests, the safest option is not to log the body at all (see [Customising a connector](#customising-a-saloon-connector-or-request)).

## Large bodies

| Body size | Default | With storage enabled |
|---|---|---|
| up to 128 KB | logged in full | logged in full |
| 128 KB – 5 MB | cut to 128 KB | cut to 128 KB **+ full copy on disk** |
| 5 MB – 20 MB | not read | cut to 128 KB **+ full copy on disk** |
| over 20 MB | not read | not read |

Enable storage:

```dotenv
HTTP_LOGGER_STORAGE_ENABLED=true
HTTP_LOGGER_STORAGE_DISK=gcs-logs     # any disk from config/filesystems.php
```

The body is masked, then saved as one file per body in a folder per day, and the entry points to it:

```json
"body": "{\"offers\":[{\"id\":\"H1\"... [truncated, 128 KB of 3.2 MB shown]",
"body_file": {
  "disk": "gcs-logs",
  "path": "http-logs/2026/09/24/150116-outgoing-42fa81c88f223f83-response.json",
  "size": 3355443
}
```

Deleting old files: use a bucket lifecycle rule if your disk has one. Otherwise, schedule the prune command (default retention: 30 days):

```php
// routes/console.php
Schedule::command('http-logger:prune')->daily();      // or: http-logger:prune --days=90
```

## Configuration

Everything is in `config/http-logger.php`. The most used env variables:

| Env | Default | |
|---|---|---|
| `HTTP_LOGGER_ENABLED` | `true` | master switch |
| `HTTP_LOGGER_CHANNEL` | default channel | log channel |
| `HTTP_LOGGER_WRITE_AFTER_RESPONSE` | `true` | `false` = write immediately |
| `HTTP_LOGGER_STORAGE_ENABLED` | `false` | store large bodies |
| `HTTP_LOGGER_STORAGE_DISK` | `local` | disk for large bodies |
| `HTTP_LOGGER_RETENTION_DAYS` | `30` | used by `http-logger:prune` |
| `HTTP_LOGGER_INCOMING_REQUEST_MESSAGE` | `incoming-request` | also `…_INCOMING_RESPONSE_…` |
| `HTTP_LOGGER_OUTGOING_REQUEST_MESSAGE` | `outgoing-request` | also `…_RESPONSE_…`, `…_FAILURE_…` |

Messages can use placeholders, e.g. `flow-to-{supplier}` or `{route} answered {status}`.

Other settings in the config file:
- log levels
- whether to log headers and bodies
- masking rules
- size limits
- paths excluded from incoming logging (`up`, `horizon*`, `telescope*`, … by default)

## Customising a Saloon connector or request

```php
class CreatePaymentRequest extends Request implements ConfiguresLogging
{
    public function configureLogging(LoggingOptions $options, PendingRequest $pendingRequest): LoggingOptions
    {
        return $options
            ->withoutRequestBody()                          // never log card payloads
            ->withoutBodyStorage()
            ->withOutgoingMessages(request: 'checkout-to-payment');
    }
}
```

Other options:
- `disable()`
- `withLogger(Log::channel('suppliers'))`
- `redactKeys(...)`
- `withoutHeaders()`
- `withoutResponseBody()`
- `withMaxBodyBytes(...)`
- `with(logHeaders: false, …)`

## Good to know

- **Writing after the response:** during web requests, entries are written after the response is sent, in the order things happened. `occurred_at` holds the real event time. In artisan commands and queue workers, entries are written immediately. If the PHP process dies before the response is fully finished, that request's entries are lost.
- **Async Saloon failures:** for `sendAsync()` and pools, connection failures are not logged (a Saloon limitation). Responses are.
- **Retries:** every retry attempt is logged with its own `correlation_id`.
- **Tests:** set `HTTP_LOGGER_THROW_ON_ERROR=true` to surface logger errors, or `HTTP_LOGGER_ENABLED=false` to silence logging.

## Development

```bash
composer lint                 # fix style (Pint) and refactor (Rector)
composer test                 # style check, PHPStan (max) and tests with exactly 100% coverage
composer test:type-coverage   # 100% type coverage
```

`composer test` needs a coverage driver (`pecl install pcov`). To release, tag a version (`git tag v0.1.0 && git push origin v0.1.0`) and update `CHANGELOG.md`.

## License

MIT. See [LICENSE.md](LICENSE.md).
