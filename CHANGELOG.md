# Changelog

All notable changes to this package will be documented in this file.

The format is based on Keep a Changelog, and this project follows Semantic
Versioning.

## [Unreleased]

### Added

- Add the explicit `fakturownia.invoice.ksef.ensure_accepted` operation with
  disjoint SDK-send and provider-auto ownership profiles, deadline polling,
  lost-response reconciliation, and a hard one-write budget.
- Persist typed current KSeF state and append-only observation history in the
  same PostgreSQL transaction as operation outcomes.
- Dispatch the semantic `InvoiceKsefAccepted` event and expose
  connection-scoped KSeF state queries.
- Add the durable `fakturownia.invoice.pdf.download` operation with bounded PDF
  staging, immutable SHA-256 addressing, one-write execution, and read-only
  lost-response reconciliation.
- Persist connection-scoped artifact descriptors with versioned AES-256-GCM
  metadata, expose integrity-checked artifact streams, and dispatch
  `InvoicePdfReady` only after descriptor and object projection agree.

### Changed

- Require `cieplik206/laravel-integration-operations` 0.3.5 so a reconciled,
  externally started KSeF send can safely return to observation polling and
  canonical transport contracts can represent fixed endpoint suffixes.

## [0.1.5] - 2026-08-26

### Fixed

- Trust copied runtime extension fixtures without weakening pre-autoload
  integrity verification.

## [0.1.4] - 2026-08-26

### Fixed

- Make security fixtures independent of the local test runner path.

## [0.1.3] - 2026-08-26

### Fixed

- Resolve the trusted pre-autoload fixture root independently of the current
  working directory.

## [0.1.2] - 2026-08-26

### Fixed

- Accept canonical root-owned executable targets behind protected merged-`/usr`
  directory aliases without weakening executable integrity checks.
- Pin dynamically loaded POSIX and Sodium module paths, hashes, and no-ini PHP
  arguments in pre-autoload policy/manifest contract v2, keeping local and CI
  runtimes equally fail-closed.

## [0.1.1] - 2026-08-26

### Fixed

- Integrity-pin canonical regular targets of loaded PHP INI symlinks so the
  live-evidence runtime snapshot remains safe and portable across CI runtimes.
- Install the POSIX extension explicitly in every CI profile required by the
  fail-closed pre-autoload supervisor tests.

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
