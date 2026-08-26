# Laravel Fakturownia

Laravel package foundation for integrating the
[Fakturownia](https://fakturownia.pl) / [InvoiceOcean](https://invoiceocean.com)
REST API.

The package provides credential-safe multi-connection configuration, an
isolated Saloon transport, typed read and command contracts, provider-owned
resource and artifact persistence contracts, Laravel package discovery, an
explicit install command, and a stateful SDK boundary built on
`cieplik206/laravel-integration-operations`. Runtime capabilities remain
fail-closed until their versioned evidence and release gates are satisfied.

## Capability policy

The versioned [capability matrix](docs/capability-matrix.json) is the authority
for every public API. Unknown capabilities and classifications are denied,
deferred capabilities are disabled, and an API requiring live evidence remains
unavailable until its allowlisted semantic fixture and SHA-256 evidence pass.
Runtime `deployment_stage` is only consumer deployment metadata. In particular,
`non_production` never means DEMO evidence and cannot enable a capability;
`demo_pl`, `demo_regional`, and `ksef_demo` belong exclusively to signed contract
probe artifacts and are rejected by runtime connection configuration.
The package deliberately registers only its no-I/O diagnostic operation until
the managed-write contracts are released. Installing or booting it cannot
trigger HTTP, queue work, or database migrations. Typed remote reads and
managed-write contracts existing in source do not become runtime authority by
themselves.

## Requirements

- PHP 8.4 or newer;
- Laravel 13;
- `cieplik206/laravel-integration-operations`;
- PHP cURL, JSON, and Sodium extensions.

## Development setup

    composer install
    composer check

A release candidate must commit its resolved `composer.lock` after the
dependency surface is frozen so release and contract evidence are
reproducible. Installed `vendor` dependencies and PHPUnit/PHPStan/Pint caches
remain local-only and are excluded by `.gitignore`.

`composer check` is the canonical, cross-platform release gate. It validates
Composer metadata and its lock, checks Pint formatting, runs PHPStan at level 7,
runs every offline Pest test, requires the real PostgreSQL suite without skips,
and audits installed dependencies. Run the gate in separate PHP 8.4 and PHP 8.5
environments before release; Composer's PHP and Laravel constraints define the
supported matrix. The active repository CI mirrors those constraints and runs
minimum, locked, latest, and real PostgreSQL gates, but a green remote workflow
does not replace the signed release manifest.

Every release commit and annotated SemVer tag must be signed by a key listed in
`.github/release-signers`. The release workflow verifies both signatures and
requires the tag commit to belong to `main`. Published stable tags are
immutable: publish a new version instead of moving or replacing a tag.

The GitHub repository, Composer metadata, and Packagist package use the
canonical `cieplik206/laravel-fakturownia` name.
The former `cieplik206/laravel-fakturownia-client` package must be marked
abandoned with the new package as its replacement. A release is complete only
after Packagist resolves the tag to the same source commit.

## Invoice identity contract probe

`composer test` is fully offline and excludes the `live` and `postgres` groups;
`composer test:pgsql` is the mandatory real-database gate. The mutating
`composer probe:invoice-identity` command fails unless explicitly enabled with
`FAKTUROWNIA_CONTRACT_PROBE_ENABLED=yes`.

Run the probe only against two dedicated `s03-demo-*` throwaway tenants in the
same environment. Configure the primary and `SECONDARY_` variants of
`ENVIRONMENT`, `BASE_URL`, `TOKEN`, and `TENANT_FINGERPRINT` under the
`FAKTUROWNIA_CONTRACT_PROBE_` prefix, plus
`FAKTUROWNIA_CONTRACT_PROBE_PAYLOAD_FILE` pointing outside this repository.
Use `demo_pl` with `*.fakturownia.pl` and `demo_regional` with
`*.invoiceocean.com`. The expected fingerprint is SHA-256 of
`fakturownia-s0.3|environment|host|account_id`; a read-only account preflight
verifies it before any write.

The JSON payload must provide VAT, secondary-account and correction templates,
two distinct department IDs, complete date/buyer/currency/position fingerprint
fields, and explicit assertions that both tenants are throwaway and automatic
KSeF/email delivery is disabled. Discounts and delivery fields are forbidden.
The single-attempt lost-response case uses a real transport timeout (50 ms by
default, configurable with
`FAKTUROWNIA_CONTRACT_PROBE_LOST_RESPONSE_TIMEOUT_MS`) followed only by exact
OID reads; a normal response or a non-timeout transport failure leaves the gate
inconclusive. Run once per DEMO environment; the final VAT policy remains closed
until both safe fixtures exist.

## KSeF DEMO contract probe

`composer probe:ksef-demo` is a separate mutating probe and fails before HTTP
unless `FAKTUROWNIA_KSEF_DEMO_PROBE_ENABLED=yes`. Keep its JSON configuration
outside this repository and point
`FAKTUROWNIA_KSEF_DEMO_PROBE_CONFIG_FILE` at it. The default `composer test`
command excludes this probe with the rest of the `live` group. Protect the
external file with owner-only permissions and never commit it.

The configuration must define four isolated `s04-demo-*` Fakturownia.pl
throwaway tenants named `explicit_block`, `explicit_persist`, `auto_block`, and
`auto_persist`. Each profile supplies its base URL, token, tenant fingerprint,
valid and deliberately invalid VAT templates, expected validation field, and a
non-expired operator attestation of `ownership`, `validation_mode`,
`gov_auto_send_mode`, `validate_invoices_for_gov`, and `buyer_company`. The
tenant fingerprint is SHA-256 of
`fakturownia-s0.4|profile_key|host|account_id`. The top-level safety object must
explicitly confirm throwaway DEMO use and disabled email, payment, and webhook
side effects. Auto-send profiles use the conservative `pl_companies` mode, and
every template explicitly sends `buyer_company=true` and `buyer_country=PL`.
Generate the settings fingerprint with
`KsefDemoProfile::settingsFingerprintFor()` from those exact attested values.

All four `/account.json` fingerprints are verified before the first write.
Invoice issue never contains `gov_save_and_send` or `send_to_ksef`;
`ensure_accepted` is a separate step that sends exactly once for `ExplicitSdk`
and only observes for `ProviderAutoSend`. The fixture stores only normalized
profiles, HTTP/KSeF status codes, send counts, exact-search counts, and PDF
MIME/size/SHA/equality metadata. It never stores credentials, hosts, remote
IDs/OIDs, invoice data, full errors, links, headers, or PDF bytes. Equal and
changed pre/post-acceptance PDF hashes are both valid evidence. A complete
matrix selects only `ExplicitSdk + BlockInvalid` for capability 0.2;
`PersistWithErrors`, provider auto-send, payments, webhooks, and low-level
`gov_save_and_send` remain outside the pilot path.

## Installation and connections

Install the published package and publish its configuration explicitly:

    composer require cieplik206/laravel-fakturownia
    php artisan fakturownia:install

The command only publishes configuration. It does not run DDL, perform HTTP,
dispatch queue work, or validate credentials. Configuration is resolved lazily
when a connection is requested, so missing or invalid values do not break
application boot and fail closed at first use.

The connection also exposes a provider- and connection-scoped view of durable
operations stored by the shared kernel:

```php
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

$operations = app(FakturowniaManager::class)
    ->connection(new ConnectionKey('sales'))
    ->operations();

$snapshot = $operations->find(new OperationId('01J00000000000000000000000'));
```

The wrapper delegates to the kernel's shared `OperationQuery` and always adds
the exact `fakturownia` provider plus selected connection scope. It is not a
second state store and cannot read another connection's operations.

The stateful package also contains local sync-integrity contracts for later
master-data lanes. `SnapshotAttestor` creates versioned HMACs separated by the
exact connection and lane; `FullSnapshotAuditor` compares a completed remote
inventory with stored attestations and reports additions, changes,
restorations, and tombstones without retaining remote identifiers or payloads.
These contracts perform no HTTP or persistence and do not open the deferred
master-data capabilities by themselves.

Configure an exact HTTPS origin and repeat the same structure under
`connections` in `config/fakturownia.php` for additional accounts:

    FAKTUROWNIA_DEPLOYMENT_STAGE=production
    FAKTUROWNIA_BASE_URL=https://my-company.fakturownia.pl
    FAKTUROWNIA_ALLOWED_HOSTS=my-company.fakturownia.pl
    FAKTUROWNIA_TOKEN=your-api-authorization-code
    FAKTUROWNIA_CONNECT_TIMEOUT_SECONDS=10
    FAKTUROWNIA_REQUEST_TIMEOUT_SECONDS=30

`FAKTUROWNIA_ALLOWED_HOSTS` is an exact comma-separated host allowlist; wildcard
and suffix matches are not accepted. The internal transport disables absolute
endpoint overrides and implicit write retries. Its typed read redirect policy
permits only explicitly allowlisted HTTPS artifact hosts and never forwards
credentials across origins. Managed remote mutations stay unavailable until a
provider definition is registered after the corresponding evidence gate. Do
not commit real credentials. Tokens must be supplied by the consuming
application's secret management and are never resolved into a singleton
credential object.

## License

The package is released under the MIT License in LICENSE.md.
