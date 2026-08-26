# Changelog

All notable changes to this package will be documented in this file.

The format is based on Keep a Changelog, and this project follows Semantic
Versioning.

## [Unreleased]

## [0.1.0] - 2026-08-26

### Added

- Initial Laravel package and development-tooling scaffold.
- Credential-safe, exact-host multi-connection foundation for Laravel 13.
- Explicit configuration install command and fail-closed capability matrix.
- Typed read, invoice, correction, KSeF, artifact, reconciliation, and
  provider-owned persistence contracts guarded by versioned capabilities.
- Connection-scoped durable operation reads delegating to the shared kernel.
- Scope-separated HMAC snapshot attestations and full-audit drift detection for
  later master-data synchronization lanes, including explicit tombstone
  mutations.

### Changed

- Renamed the Composer package to `cieplik206/laravel-fakturownia`.
- Raised the runtime floor to PHP 8.4 and Laravel 13.
- Declare the Illuminate Console and Database components used by the Laravel
  adapters as direct runtime dependencies.
- Require signed release commits and annotated tags verified against the
  repository signer allowlist.
