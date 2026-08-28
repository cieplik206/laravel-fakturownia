# Fakturownia native live-evidence broker

This crate is the privileged, fail-closed half of the SDK live-evidence harness. It is not a general Fakturownia client and is not installed or started by Composer.

## Current status

The source implementation and offline tests are present. No production deployment, key provisioning, provider credential, authorization bundle, or live run is part of the repository. An ordinary build is deliberately unusable: the binary exits with code `78` unless all compile-time deployment pins are supplied and a matching signed, root-owned policy is installed at the compiled path.

The PHP child never receives provider API tokens, provider commitment keys, authorization signing keys, or result signing keys. It submits only an allowlisted, bounded effect proposal with a public target role. The root credential set maps that role to the exact authorized profile and isolated tenant, creates the secret commitments, allocates the effect in its compare-and-set store before HTTP, performs at most one request, and returns a signed result.

## Build gates

Rust 1.88 or newer is required. Run from this directory:

```sh
cargo fmt --check
cargo test --all-features
cargo clippy --all-targets --all-features -- -D warnings
cargo check --target x86_64-unknown-linux-gnu --no-default-features
```

The last command checks the Linux-only policy and process code without linking the network transport. A production release must additionally be compiled and tested on the exact target Linux image; a macOS cross-check without the target linker/sysroot is not a release artifact.

## Compile-time deployment identity

A reviewed build sets all four values below:

```sh
FAKTUROWNIA_NATIVE_BROKER_DEPLOYMENT=reviewed-v1 \
FAKTUROWNIA_NATIVE_POLICY_SIGNER_ID='<pinned-signer-id>' \
FAKTUROWNIA_NATIVE_POLICY_PUBLIC_KEY_BASE64='<canonical-32-byte-ed25519-public-key>' \
FAKTUROWNIA_NATIVE_SUPERVISOR_SEMANTICS_SHA256='063386ef725cd5c0a38204cdb809a2ae73909e3c34b801098d6e82258f1a9dd1' \
cargo build --release --locked
```

Those variables are embedded at compile time. Runtime environment variables and command-line arguments cannot override them. The executable accepts no arguments and reads exactly one signed policy from:

```text
/etc/cieplik206/fakturownia-live-evidence/native-supervisor-policy.json
```

`supervisor-semantics-v1.json` is the review input for the compiled semantics digest. Changing broker behavior, wire semantics, the operation allowlist, credential placement, CAS rules, or process guarantees requires a new reviewed semantics document and digest.

## Privileged deployment requirements

The signed native policy pins the supervisor executable and SHA-256, PHP executable and SHA-256, PHP launcher and SHA-256, launcher policy and SHA-256, launch manifest, UID/GID, trust policy, authorization bundle, the profile/target credential set, signing seeds, CAS root, argv, environment, authority identities, and every corresponding content hash.

All policy, executable, key, credential, authorization, and CAS paths are absolute. Trusted files are root-owned, non-hardlinked regular files and are not group/world-writable. Secret files are mode `0400` or stricter. Trusted ancestors are root-owned and not group/world-writable. The probe UID/GID are dedicated non-root identities. Linux Yama `ptrace_scope` must be exactly `3`; kernels without that immutable no-attach boundary are rejected before the child or provider credential is opened.

Provisioning must be performed by a separately reviewed root installer. Do not add a developer bypass, alternate policy argument, runtime key override, fallback HTTP client, or direct PHP credential transport.

## Process and wire sequence

1. The root supervisor verifies its signed policy and every prelaunch asset.
2. It verifies the host-wide no-attach ptrace policy, hardens itself, clears supplementary groups, enables `NO_NEW_PRIVS`, disables core dumps/dumpability, and starts exactly `<pinned PHP> -n <pinned launcher> --supervised` as the pinned probe UID/GID with an empty allowlisted environment.
3. It sends one canonical length-prefixed launch frame over the child's anonymous stdin pipe.
4. The pre-autoload PHP launcher verifies its root policy, runtime, signed manifest, package inventory, and immutable snapshot twice before returning a bound `READY` frame.
5. Only after `READY`, the root supervisor verifies/opens signing material and sends a signed, non-secret authority handoff.
6. The verified PHP entrypoint selects S0.3 or S0.4 only from the signed authority contract. It may submit exact bounded read proposals, single effect proposals, or the one allowlisted S0.3 pair of same-OID creates.
7. For a read, the broker validates the exact GET path and target, performs one bounded request, verifies account identity when required, and returns a signed observation. For an effect, it validates the authorization/profile/operation/path/bounds, allocates CAS, performs at most one provider request, and returns the descriptor, signed result, and a second signed secret-free receipt containing only the descriptor and result digests/metadata.
8. S0.3 accepts exactly 11 effect sequences and S0.4 accepts exactly eight fixture creates plus two explicit sends. The same-OID batch contains exactly two byte-identical request bodies and is dispatched concurrently only after both proposals pass every check.
9. EOF closes the session. Any mismatch terminates the process and does not create a second provider attempt.

The anonymous pipe pair is created by the root parent and inherited only by the exact pinned child. It has no filesystem name and no public connection surface. Wire frames use an eight-character lowercase hexadecimal payload length, newline, and recursively canonical JSON. The current immutable wire version is `1`.

## Non-goals

- This crate does not provision keys, credentials, DEMO tenants, system users, or system services.
- It does not authorize a production or live run.
- It does not replace the external atomic authorization-consumption authority.
- It does not make an ambiguous provider timeout retryable. A crash after CAS allocation but before a sealed result becomes a signed `possibly_applied` result and never a second HTTP request.

See `../docs/live-evidence-preautoload-runbook.md` for the complete snapshot, rollout, rollback, and failure contract.
