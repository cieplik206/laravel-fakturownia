# Pre-autoload trust root for live evidence

> **Deployment status:** live execution is intentionally fail-closed. The shipped PHP launcher refuses every production invocation until the separately reviewed native root supervisor/broker described below is implemented, installed, and cryptographically bound. Offline tests and offline SDK use remain available. Do not enable live execution by changing the fail-closed constant.

This runbook specifies the required production path for starting a Fakturownia live-evidence probe. The PHP launcher is deliberately outside Composer: it declares all of its code in one file and never loads `vendor/autoload.php` itself. Composer code may eventually run only inside a content-addressed snapshot after a native root broker and two complete verification passes authorize it.

## Trust boundary

The completed fixed trust root must be:

- a native root-owned supervisor/broker with no general-purpose exec or caller-controlled configuration;
- `/usr/local/libexec/cieplik206/fakturownia-live-evidence-launcher.php`, installed from `bin/fakturownia-live-evidence-launcher.php`;
- `/etc/cieplik206/fakturownia-live-evidence/preautoload-policy.json` (the path is compiled into the launcher);
- a raw 32-byte Ed25519 public key at the absolute path pinned by the policy;
- the absolute PHP executable pinned by path and SHA-256 in the policy;
- root-owned manifest and snapshot roots pinned by the policy.

The Ed25519 private key must remain in an external offline signing service or hardware-backed operator environment. It must never exist in this repository, a snapshot, the probe host, a Composer script, an environment variable, or a probe credential file.

The production launcher and trust material are root-owned. The launcher refuses to run as root. Snapshot files, snapshot directories, raw manifests, detached signatures, and the public key have no owner/group/other write bits. Snapshot files have one hard link. Every ancestor in the trust paths is root-owned and not group/world-writable.

There is no supported developer-mode, alternate policy argument, key override, environment bypass, or direct PHP live invocation. Until the native broker exists, the launcher exits `78` before parsing caller manifest/credential/authorization arguments.

## Policy contract

The policy is strict JSON with no duplicate or additional keys:

```json
{
  "contract": "cieplik206.fakturownia.preauthenticated-policy",
  "version": 2,
  "manifest_root": "/var/lib/cieplik206/fakturownia-live-evidence/manifests",
  "snapshot_root": "/var/lib/cieplik206/fakturownia-live-evidence/snapshots",
  "public_key_path": "/etc/cieplik206/fakturownia-live-evidence/operator-ed25519.pub",
  "public_key_sha256": "<64 lowercase hex>",
  "php_executable": "/usr/bin/php8.4",
  "php_executable_sha256": "<64 lowercase hex>",
  "php_extensions": {
    "posix": {"path": null, "sha256": null},
    "sodium": {
      "path": "/usr/lib/php/20240924/sodium.so",
      "sha256": "<64 lowercase hex>"
    }
  },
  "launcher_sha256": "<64 lowercase hex>",
  "probe_entrypoint": "tests/Contract/LiveEvidenceProbeEntrypoint.php",
  "limits": {
    "manifest_bytes": 16777216,
    "manifest_depth": 64,
    "manifest_nodes": 500000,
    "tree_files": 100000,
    "tree_directories": 50000,
    "path_bytes": 1024,
    "file_bytes": 536870912,
    "tree_bytes": 4294967296,
    "credential_bytes": 1048576,
    "authorization_bytes": 1048576
  }
}
```

All absolute paths must already equal `realpath()` and may not contain `.` or `..` components. Values in `limits` can be lowered but cannot exceed the launcher's compiled ceilings. Each required extension uses `null` path and hash only when it is compiled into the pinned PHP binary. A dynamically loaded POSIX or Sodium module requires its canonical path and SHA-256; the launcher validates the protected file and derives the only accepted `-d extension=...` arguments from this policy.

## Signed manifest contract

The release builder creates one strict JSON document with this exact top-level schema:

```text
contract, version, repository, entrypoint, bindings, runtime, directories, files
```

Required semantics:

- `contract` is `cieplik206.fakturownia.preauthenticated-snapshot`, `version` is `2`;
- `repository.commit` is the signed 40- or 64-character lowercase Git object id;
- `entrypoint` exactly equals the path pinned by policy and is present in `bindings.harness_files`;
- `directories` is a unique, bytewise-sorted inventory of `{path,type:"directory",mode}`;
- `files` is a unique, bytewise-sorted inventory of `{path,type:"file",mode,size,sha256}`;
- every path is canonical and repository-relative; no symlink, device, socket, FIFO, or hardlink is permitted;
- all directories and files in the snapshot are inventoried, so extra and missing paths both fail closed;
- `bindings.snapshot_tree_sha256` covers every file record;
- `bindings.vendor_tree_sha256` covers every `vendor/` file record;
- `bindings.composer_lock_sha256` and `bindings.installed_packages_sha256` bind the raw dependency inventories;
- the normalized installed package set must exactly equal `composer.lock` `packages + packages-dev`, comparing name, version, source type/URL/reference and dist type/URL/reference/shasum;
- `bindings.composer_bootstrap_files` is the exact sorted set consisting of `vendor/autoload.php` and every `vendor/composer/**/*.php` file;
- `bindings.source_files` is the exact sorted `src/` set;
- `bindings.harness_files` is the exact sorted `tests/Contract/` set;
- `bindings.behavior_files` is the exact sorted set of every non-`vendor/` file;
- `bindings.policy_sha256`, `public_key_sha256`, and `launcher_sha256` bind the OS policy, external operator key, installed launcher, and the identical launcher copy at `bin/fakturownia-live-evidence-launcher.php` in the snapshot;
- the snapshot contains at least `composer.json`, `composer.lock`, `phpunit.xml.dist`, `tests/Pest.php`, the pinned entrypoint, `vendor/composer/installed.json`, and `vendor/autoload.php`.

The tree digest input is canonical JSON with recursively bytewise-sorted object keys:

```json
{
  "contract": "cieplik206.fakturownia.snapshot-file-set",
  "version": 2,
  "files": ["the relevant sorted exact file records"]
}
```

`runtime` contains this exact key set:

```text
php_executable, php_executable_sha256, php_version, php_version_id,
sapi, arguments, ini, extensions, zend_extensions
```

It binds the policy-pinned executable and hash, exact PHP version/version id, `cli`, the exact no-ini argument list derived from `php_extensions`, no loaded or scanned ini, empty `auto_prepend_file` and `auto_append_file`, and bytewise-sorted exact normal and Zend extension lists. The argument list always starts with `["-n"]`; each dynamic required module appends the exact pair `["-d", "extension=<canonical-policy-path>"]` in POSIX, Sodium order. Capture the extension lists with those exact arguments; do not derive them from a Composer process that loaded an ini file.

The signer signs the raw manifest bytes with detached Ed25519. The signature file is raw 64-byte output, not base64. If `H` is the lowercase SHA-256 of those exact raw bytes, install:

```text
<manifest_root>/H.manifest.json
<manifest_root>/H.manifest.sig
<snapshot_root>/H/
```

Changing JSON whitespace changes `H` and invalidates the signature/content address by design.

## Offline provisioning

Provisioning is a privileged release operation, never a launcher feature. The launcher must not copy from an owner-writable checkout.

1. In a clean, reviewed release builder, export the exact signed commit without `.git`, credentials, caches, sockets, or generated live evidence.
2. Install dependencies from the signed `composer.lock`, including development packages required by the contract harness. Disable Composer scripts and plugins unless their exact frozen output was independently produced and reviewed.
3. Add the reviewed dedicated probe entrypoint. Build the exact directory/file inventories and all bindings above.
4. Generate the raw manifest, sign its exact bytes in the offline signer, calculate `H`, and discard any local private-key material according to the signer policy.
5. A root-qualified installer independently rejects symlinks, hardlinks, special files, path traversal, package-set mismatches, and inventory mismatches before copying. It materializes a new staging directory on the same filesystem as the snapshot root, sets file ownership to root and modes to `0444`, sets directory ownership to root and modes to `0555`, fsyncs files and directories, and atomically renames staging to `<snapshot_root>/H`.
6. Install the raw manifest, raw signature, public key, policy, and launcher as root. Manifest/signature/key/launcher mode is `0444`; policy mode is `0644` or stricter; their parent directories are root-owned `0755` or stricter.
7. Recalculate and compare the launcher, policy, PHP binary, public key, manifest, and full snapshot hashes after installation. Never repair an existing content address in place. Build and install a new `H` instead.
8. Drop privileges permanently to a dedicated probe account before launch. That account must not own or be able to write the launcher, policy, key, manifest roots, snapshot roots, snapshots, PHP executable, or their ancestors.

An owner-owned checkout made `chmod 0444/0555` is not a production snapshot: its owner can restore write permission. Root ownership plus an unprivileged probe identity is mandatory.

## Required native supervisor contract (not yet shipped)

There is currently no supported live invocation command. Calling the PHP launcher directly always exits `78`. A deployment may enable live execution only after a separately approved native supervisor implements every requirement in this section and the PHP launcher's fail-closed gate is replaced by verification of that exact protocol.

The supervisor must run as root but may not be setuid, provide a login shell, accept arbitrary executable/argument/environment overrides, or trust path/hash/UID values from caller argv or environment. Its only runtime action is one fixed policy-selected probe. It must:

1. load a bounded, exact-schema supervisor policy envelope from one compiled absolute path;
2. verify the raw policy bytes with detached Ed25519 against a compiled or root-owned pinned public key fingerprint;
3. take the exact launch manifest SHA-256, PHP path/hash, launcher path/hash, supervisor path/hash, probe UID/GID, dedicated entrypoint, credential/A paths and byte limits from that signed policy;
4. verify its own binary, the raw launch manifest, PHP binary, launcher, launcher policy, owners, modes, ancestors, link counts, and hashes before `fork`/`execve`;
5. reject all caller arguments, including attempted `-d`, `extension=`, `auto_prepend_file`, alternate PHP, alternate manifest, alternate UID, or alternate secret paths;
6. construct the child argv exactly as `<pinned PHP>, -n, <pinned launcher>, --supervised`, with no `-d` element before or after the script;
7. construct an exact allowlisted environment containing only `PATH=<pinned PHP directory>`, `LANG=C`, and `LC_ALL=C`; hashes of the ordered argv and environment contracts are signed by supervisor policy and launch manifest;
8. create private inherited channels before dropping privileges, disable core dumps, disable dumpability/ptrace, set `NO_NEW_PRIVS` where available, clear supplementary groups, and permanently set the policy-signed no-login probe GID/UID;
9. send the policy-selected manifest hash to the pre-autoload verifier only through a private authenticated control channel; no caller env or public factory supplies it;
10. wait for `READY <manifest_sha256> <supervisor_semantics_sha256>` from the child only after the snapshot was materialized and verified twice;
11. keep the provider credential exclusively in the root broker; credential bytes must never be inherited, mapped, copied, or passed to child PHP through FD, argv, environment, file path, shared memory, or serialized payload;
12. verify the complete signed authorization `A` set and generate a fresh run nonce, then obtain a detached supervisor attestation signed outside PHP by a root-only or hardware-backed broker key over the exact manifest hash, run nonce, canonical A-set hash, broker policy hash, and expiry;
13. pass only the non-secret signed supervisor attestation and verified manifest identity to the child over root-authenticated private AF_UNIX handoff; a PHP object or WeakMap brand alone is never authority evidence because Reflection can forge it;
14. accept only canonical, authorization-bounded effect descriptors from the verified child over a private broker socket. Each descriptor binds manifest hash, run nonce, A-set hash, effect id, method, canonical provider path, request commitment, response policy, and deadline;
15. perform a root-side compare-and-set for that exact effect id before network activity, execute exactly one policy-selected provider HTTP request inside the broker, seal the response, and return a detached signed result. The child never receives a reusable transport, credential, arbitrary URL/method capability, or second attempt;
16. close every handoff without opening the credential or producing a CAS/provider effect when READY, manifest, nonce, A-set, attestation, descriptor, deadline, or response binding differs.

The dedicated verified entrypoint may consume `Cieplik206\Fakturownia\ContractTesting\LiveEvidence\VerifiedLaunchManifest::consumeFromSupervisorFd6()` as a convenient one-shot transport for exact `[a-f0-9]{64}\n` plus EOF from a root AF_UNIX peer. That object is not sufficient proof for a claim. Its `sha256()` is written to exact `harness.launch_manifest_sha256`, while the consumption authority independently verifies the native supervisor's detached attestation and its signer/key policy.

Signed `A`, the consumption claim, grant/receipt, broker CAS record, signed provider result, run evidence, and final attestation all bind the identical `harness.launch_manifest_sha256`, run nonce, and canonical A-set hash. A READY/manifest swap or an attestation made for another nonce/A-set must release neither secret material nor an effect/result.

Credential and authorization source files are root-owned, non-hardlinked regular files with mode `0400` or stricter. Earlier or concurrent processes running under the probe UID cannot open those paths, inherit broker capabilities, inspect the non-dumpable child, attach to the root broker, or access its sealed credential/provider state. The exact descriptor allocation and supervisor-attestation wire schema must be frozen jointly with the remote consumption authority before the fail-closed gate is removed; FD 3/4 must not be used to pass provider credentials to PHP.

Until this native supervisor is reviewed and provisioned, no command in this repository is a live execution path.

## N/N-1 supervisor and broker rolling deployment

This procedure becomes executable only after the native supervisor and broker
exist, pass their dedicated security review, and are pinned by a signed release
manifest. It does not authorize replacing the current fail-closed launcher.

Release N and N-1 may coexist only when both accept the same versioned wire
envelope and independently verify the exact peer binary, policy, signer,
manifest, snapshot, UID/GID, argv, and environment bindings. A wire version is
immutable; incompatible semantics require a new version and a staged broker
that explicitly supports both versions.

1. Build supervisor, broker, launcher snapshot, and PHP dependencies from the
   signed release commits. Record their hashes in the release manifest.
2. Install N alongside N-1 at new content-addressed paths. Never overwrite a
   binary, policy, key, manifest, or snapshot in place.
3. Run the offline verifier and a no-effect readiness handshake for N. The
   broker must not open the provider credential or allocate an effect CAS record
   during readiness.
4. Confirm that N supports every unexpired authorization, claim, and CAS record
   created by N-1. N-1 must reject N-only wire versions without changing their
   state.
5. Route one explicitly authorized throwaway DEMO run to N. Keep N-1 available
   for already-started N-1 runs; never move a run nonce, authorization set, CAS
   record, or result receipt between broker generations.
6. Compare signed result receipts, failure codes, latency, and redaction output.
   Do not use provider payloads, OIDs, remote IDs, or credentials as metrics.
7. Increase the N cohort only after one complete authorization and evidence
   retention window without verifier, CAS, or receipt mismatch.
8. Roll back by routing new runs to the still-installed N-1 content address.
   Let N-owned runs finish or expire under N; never replay them through N-1.
9. Remove N-1 only after every N-1 authorization, run nonce, CAS record, signed
   receipt, and evidence retention deadline has expired and the audit is empty.

Any mismatch closes the N cohort, leaves the provider credential unopened when
possible, preserves existing CAS/receipt state, and requires a new signed
release. Editing a policy, weakening verification, copying a nonce, or issuing a
second provider request is not a rollback.

## Failure and rotation

Missing native-supervisor deployment, direct PHP invocation, or any schema, signature, content address, runtime, mode, owner, link-count, tree, package, source, harness, Composer bootstrap, policy, key, launcher, peer-credential, READY, argv, environment, UID/GID, seal, or manifest-handoff mismatch exits with code `78` before the credential/authorization files are opened. Do not retry by weakening policy or editing a snapshot.

To rotate the operator key, install a new root-owned public key and policy, then create newly signed manifests that bind both new raw hashes. Keep old snapshots quarantined until their retention period expires; never accept both keys implicitly. To roll back application behavior, launch a separately signed older content address whose policy/key/runtime bindings still match current trusted OS material.
