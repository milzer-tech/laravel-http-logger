# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Requires Saloon `^4.0` (Saloon v3 is affected by CVE-2026-33942, CVE-2026-33182 and CVE-2026-33183).
- `HasLogging` Saloon plugin logging requests, responses and fatal failures as PSR-3 entries.
- Content-aware body handling: JSON, XML/SOAP, form, multipart, text, binary and streams.
- Redaction of headers, query parameters and body keys (structured and raw XML/JSON/form).
- Body truncation and parse limits, correlation ids, response timing.
- `ProvidesLogContext` and `ConfiguresLogging` contracts for per-connector/request customisation.
- Quality gates: Pint, Rector, PHPStan (max, incl. tests), 100% code coverage and 100% type coverage (`composer test`, `composer test:type-coverage`).
- Laravel service provider with publishable config; message names overridable via `SALOON_LOGGER_*_MESSAGE` env variables.
