# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
