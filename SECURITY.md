# Security policy

## Reporting a vulnerability

Please do not disclose security vulnerabilities in public issues. Use GitHub's
private vulnerability reporting for this repository once it is available:

https://github.com/cieplik206/laravel-fakturownia/security/advisories/new

Include the affected version, a reproduction, expected impact, and suggested
mitigation. Never include live Fakturownia credentials in a report.

## Credentials

API tokens must remain in the consuming application's secret management or
environment configuration. Do not put them in source code, fixtures, logs,
issues, pull requests, or the untracked comparison materials.

## Credential boundary

Every connection must provide an exact allowlisted HTTPS origin. The public
API exposes neither arbitrary request dispatch nor a raw credentialed
connector. The provider-owned transport disables absolute endpoint overrides
and implicit retries, enforces exact typed descriptors, and follows only
allowlisted cross-host artifact redirects without forwarding credentials.
Credential cloning, serialization, native/Symfony debug output, and
`var_export()` are structurally redacted. Connection configuration is resolved
lazily and is never stored in a singleton credential object.

The architecture suite rejects unmanaged Saloon requests and every known
alternate dispatch path. Typed remote reads and managed-write contracts remain
behind versioned evidence gates; source availability alone never authorizes a
remote effect. A connection's `deployment_stage` is metadata, not evidence;
only the capability matrix and its pinned evidence can authorize an operation.

## Release authenticity

Release commits and annotated SemVer tags must carry valid SSH signatures from
`.github/release-signers`. The release workflow verifies both signatures and
that the tagged commit belongs to `main` before it may create a GitHub release.
Never move a stable tag after publication; release a new version instead.

## Support status

The latest `0.1.x` release line receives security fixes while it remains
compatible with the supported PHP 8.4/8.5 and Laravel 13 dependency matrix.
Report privately as soon as practical; an acknowledgement and remediation
timeline will be provided after triage according to severity.
