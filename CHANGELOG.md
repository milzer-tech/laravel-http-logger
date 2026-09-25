# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.3.0] - 2026-09-25

### Added
- Logging for Laravel's `Http` facade (and any Guzzle client) with the `HttpLogger::middleware()`
  Guzzle middleware: register it globally (`Http::globalMiddleware(...)`) or per client
  (`Http::withMiddleware(...)`), add properties per middleware or per call (`log_context` option),
  and adjust options such as messages. Logs requests, responses, connection failures (including
  async and pooled requests and handlers that throw), retries and redirects, shares the trace id
  with incoming requests, and logs once even if registered twice.

### Security
- Fixed: the first query parameter of a URL inside free text (e.g. the URL Guzzle appends to
  connection errors: `... for https://host/?api_key=...`) was not masked since 0.2.0.

## [0.2.0] - 2026-09-25

### Security
- Failure entries and the logger's own error entry log a masked `error` block (type, message,
  file, line, trace, previous) instead of the raw exception, whose message can contain URLs with
  API keys (Guzzle appends the request URL, query string included).
- Multipart fields with nested names (`payment.card_number`, `payment[card_number]`) are masked
  when any name segment is sensitive.
- Sensitive XML elements are masked even when they contain child elements.
- Credentials in URLs (`https://user:pass@host`) and free-text `key=value` pairs are masked in raw
  text.
- Masking fails closed: if a pattern cannot run on a body, the body is replaced by a placeholder
  instead of being logged unmasked.
- Incoming bodies are no longer read in full before the size check: `Content-Length` is checked
  first, otherwise the body is read only up to the limit. Multipart summaries stay within
  `max_body_bytes` as a whole.

### Added
- `log.exception_object` option to also pass the raw exception object (unmasked) to the logger,
  e.g. for error-reporting tools.

### Changed
- `outgoing-failure` entries and "http-logger failed to write a log entry" entries no longer
  contain the `exception` object by default; use the new `error` block, or enable
  `log.exception_object`.

## [0.1.0] - 2026-09-24

### Added
- Incoming request logging for Laravel: `LogIncomingRequests` middleware (alias `http-logger`)
  with route, client IP, authenticated user id, multipart upload summaries, streamed and
  file-download responses, path exclusions and a `ProvidesIncomingLogContext` resolver.
- Trace id shared by an incoming request and every outgoing Saloon call it makes (and the jobs
  it dispatches), via Laravel's Context. Reuses a safe `X-Request-Id` sent by the caller.
- Writing after the response: during web requests, entries are built and written after the
  response has been sent, in the order the events happened. Immediate in console and queue.
- Large body storage: full (masked) copies of oversized bodies on any Laravel/Flysystem disk,
  one file per body in per-day folders, referenced from the log entry as `body_file`.
- `http-logger:prune` command to delete stored bodies older than `storage.retention_days`.
- `direction`, `trace_id` and `occurred_at` on every entry.
- `HasLogging` Saloon plugin logging requests, responses and fatal failures as PSR-3 entries.
- Content-aware body handling: JSON, XML/SOAP, form, multipart, text, binary and streams.
- Redaction of headers, query parameters and body keys (structured and raw XML/JSON/form).
- Body truncation and parse limits, correlation ids, response timing.
- `ProvidesLogContext` and `ConfiguresLogging` contracts for per-connector/request customisation.
- Quality gates: Pint, Rector, PHPStan (max, incl. tests), 100% code coverage and 100% type
  coverage (`composer test`, `composer test:type-coverage`).
- Requires Saloon `^4.0` when used (Saloon v3 is affected by CVE-2026-33942, CVE-2026-33182 and
  CVE-2026-33183).

### Changed
- Package renamed from `milzer/saloon-logger` to `milzer/laravel-http-logger`
  (namespace `Milzer\HttpLogger`). It is a Laravel package (Laravel 11 or 12); Saloon is optional
  and only needed for outgoing logging. Internally split into `Core`, `Saloon`, `Laravel` and
  `Storage`.
- Default messages are `outgoing-request`, `outgoing-response`, `outgoing-failure`,
  `incoming-request` and `incoming-response`; all configurable.

[Unreleased]: https://github.com/milzer-tech/laravel-http-logger/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/milzer-tech/laravel-http-logger/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/milzer-tech/laravel-http-logger/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/milzer-tech/laravel-http-logger/releases/tag/v0.1.0
