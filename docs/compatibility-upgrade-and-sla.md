# Compatibility, upgrade, deployment, and SLA policy

This document is normative for the `0.3.x` release train. It describes the
supported runtime set and the order in which the integration kernel, this
provider SDK, and a consuming Laravel application are upgraded. It does not
turn a deferred capability into an enabled remote-write path.

## Supported matrix

| Component | Supported contract | Blocking evidence |
| --- | --- | --- |
| PHP | `^8.4` | The package must pass its complete gate on PHP 8.4 and 8.5 before a release declares both runtimes. |
| Laravel | `^13.0` with the narrower component floors declared in `composer.json` | Minimum, locked, and latest dependency profiles must pass. |
| Integration kernel | `cieplik206/laravel-integration-operations:^0.3.5` | The exact released kernel commit must pass its own offline, PostgreSQL, and Redis gates. |
| Database | PostgreSQL; PostgreSQL 17 is the release reference | `composer test:pgsql` must run without skips against an allowlisted disposable database. MySQL and SQLite are not release evidence. |
| Shared resilience state | Redis for multi-worker rate and circuit state | The kernel's real Redis race gate must pass. In-memory state is not production evidence. |
| HTTP transport | Saloon 4 | Transport adapters remain consumer-bound and fail closed until the capability-specific live gate passes. |
| Consumer application | Laravel 13 using one database connection for kernel operations and provider projections | The consumer must pass its full locked test gate after Packagist resolves both package tags. |

Only combinations admitted by every relevant Composer constraint are supported.
A green run with a hand-edited dependency graph, an uncommitted lockfile, a path
repository, or a branch alias is not release evidence.

## Release and upgrade order

1. Freeze and sign the kernel commit on `main`; run `composer check` with the
   real PostgreSQL and Redis gates and publish a signed annotated `0.x` tag.
2. Confirm that Packagist resolves the kernel tag to the identical commit.
3. Update the SDK lockfile from Packagist, never from a local path repository.
   Run `composer check`, sign the SDK commit and annotated tag, and publish it.
4. Confirm that Packagist resolves the SDK tag to the identical commit and the
   expected kernel constraint.
5. Update the consuming application's direct kernel and SDK requirements from
   Packagist. Commit the resulting lockfile together with the release manifest.
6. Deploy additive migrations before new code. Package discovery and install
   commands may expose migration paths but must never execute DDL.
7. Run the kernel and provider doctors with writers disabled. A missing
   definition, key, storage capability, database connection, or compatible
   persisted version is a deployment failure, not a business failure.
8. Deploy code while the legacy owner fence remains authoritative. Enable only
   a reviewed connection, operation, and document cohort after its live gate.
   Never perform a shadow remote write or dual write.
9. Observe the complete canary window before expanding a cohort. Retain N-1 code
   and keys while an N-1 operation, result, artifact, or tombstone can still be
   read or recovered.

The release manifest must bind the kernel tag and commit, SDK tag and commit,
consumer commit and lockfile hash, migration inventory, active writer
generation/mode/cohort, capability evidence hashes, and rollback procedure.

## Rolling deployment and rollback

Persisted behavior is selected by provider, operation type, payload schema,
handler version, and result schema. Release N must keep every active N-1 tuple
executable or deliberately skip it without changing the operation into a
business failure.

Rollback restores the signed N-1 application and package set and returns the
writer fence to the previously recorded generation. It does not move tags,
delete additive schema, reopen terminal operations, reuse an operation ID with
a corrected payload, or issue a compensating remote write automatically.
In-flight work remains owned by the generation recorded at acceptance. Any
ambiguous effect enters reconciliation or manual review.

## SLA expectations

The package provides durability and bounded recovery semantics, not a fixed
remote completion latency:

- acceptance is durable only after the consuming application's outer database
  transaction commits;
- dispatch is asynchronous and depends on configured queue and scheduler
  availability, per-provider budgets, per-connection fairness, rate limiting,
  circuit state, and provider availability;
- a transport timeout after an effect boundary never promises that the remote
  effect is absent; the operation moves through observation, reconciliation,
  or manual review without a blind second write;
- polling operations honor their frozen deadline and expose overdue or unknown
  provider states explicitly;
- terminal result availability distinguishes available, pruned, missing,
  corrupt, and unsupported-codec states; a missing historical payload or result
  never authorizes automatic re-issue;
- events are an after-commit fast path. Scoped operation queries and watchdogs
  are the recovery authority when an event is lost or duplicated;
- production alert thresholds and response times belong to the consumer's
  deployment manifest and runbook. They must cover pending age, retry age,
  uncertain/manual-review backlog, KSeF overdue state, queue/scheduler health,
  and artifact integrity.

## Capability gates

Source code, offline fixtures, and an installed transport do not by themselves
open a remote write. Each write requires all of the following: an active
capability classification, pinned live evidence, a registered immutable
operation definition, a reviewed transport binding, a matching writer fence,
and a release manifest that names the evidence. Deferred operations, including
the prepared proforma runtime, remain unavailable until their separate gate is
closed.
