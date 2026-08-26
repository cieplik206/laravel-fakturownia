<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\Evidence;

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final class Rt6ArtifactEvidenceContract
{
    use ValidatesDiagnosticEvidence;

    public const Contract = 'cieplik206.fakturownia.rt6-artifact-evidence';

    public const FixtureContract = 'cieplik206.fakturownia.rt6-artifact-fixture';

    public const Version = '1';

    public const Disposition = 'diagnostic_only_not_runtime_authority';

    /** @var list<string> */
    private const RequiredBooleanChecks = [
        'content_addressed_put_confirmed',
        'atomic_descriptor_projection',
        'orphan_recovery',
        'checksum_doctor',
        'shared_database_artifact_lock',
        'lease_owner_revalidated',
        'lease_renewed_before_critical_sections',
        'sealed_purge_authority',
        'purge_permit_consumed_before_delete',
        'forged_purge_permit_denied',
        'replayed_purge_permit_denied',
        'cross_target_purge_permit_denied',
        'native_unserialize_denied',
        'terminal_tombstone',
        'immutable_purge_deadline',
        'ready_to_quarantined_to_deleted',
        'ready_to_deleted_forbidden',
        'truncate_bypass_forbidden',
        'retention_policy_mismatch_blocks_delete',
        'doctor_complete_pagination',
        'full_from_origin_doctor',
        'database_lock_schema_search_path_bound',
        'schema_qualified_artifact_and_lock_tables',
        'database_lock_same_repository_writer_connection',
        'ciphertext_integrity',
        'retention_and_crash_chaos',
    ];

    private function __construct() {}

    /** @param array<string, mixed> $document */
    public static function assertValid(#[SensitiveParameter] array $document): void
    {
        self::assertCanonicalDocument($document);
        self::assertExactKeys($document, [
            'contract',
            'version',
            'disposition',
            'capability',
            'provider',
            'harness',
            'run',
            'fixture',
            'source_read_evidence',
            'remote_pdf',
            'artifact',
            'checks',
            'payload_sha256',
        ], 'RT-6 artifact evidence');

        if (self::string($document, 'contract', 'RT-6 evidence contract') !== self::Contract
            || self::string($document, 'version', 'RT-6 evidence version') !== self::Version
            || self::string($document, 'disposition', 'RT-6 evidence disposition') !== self::Disposition
            || self::string($document, 'capability', 'RT-6 capability') !== 'invoice.pdf.download') {
            throw new InvalidArgumentException('The RT-6 artifact evidence contract, version, disposition or capability is invalid.');
        }

        $provider = self::object($document['provider'] ?? null, 'RT-6 provider');
        self::assertProvider($provider);
        self::assertHarness(self::object($document['harness'] ?? null, 'RT-6 harness'));
        $run = self::object($document['run'] ?? null, 'RT-6 run');
        self::assertRun($run);
        self::assertProviderRunBinding($provider, $run);
        self::assertFixture(self::object($document['fixture'] ?? null, 'RT-6 fixture'), self::FixtureContract);
        $sourceReadEvidence = self::object($document['source_read_evidence'] ?? null, 'RT-6 source read evidence');
        $remotePdf = self::object($document['remote_pdf'] ?? null, 'RT-6 remote PDF');
        $artifact = self::object($document['artifact'] ?? null, 'RT-6 artifact');
        self::assertRemotePdf($remotePdf, $run);
        self::assertSourceReadEvidence($sourceReadEvidence, $remotePdf, $document);
        self::assertArtifact($artifact, $remotePdf, $run);
        self::assertChecks(self::object($document['checks'] ?? null, 'RT-6 checks'));
        self::assertPayloadSha256($document);
    }

    /** @param array<string, mixed> $document */
    public static function canonicalSha256(#[SensitiveParameter] array $document): string
    {
        self::assertValid($document);

        return \hash('sha256', CanonicalCodec::encode($document));
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('RT-6 diagnostic evidence guards cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): never
    {
        throw new LogicException('RT-6 diagnostic evidence guards cannot be unserialized.');
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $remotePdf
     * @param  array<string, mixed>  $document
     */
    private static function assertSourceReadEvidence(
        #[SensitiveParameter] array $source,
        #[SensitiveParameter] array $remotePdf,
        #[SensitiveParameter] array $document,
    ): void {
        Rt3ReadEvidenceContract::assertValid($source);

        $cases = self::list($source['cases'] ?? null, 'source read cases', 16);
        $success = self::object($cases[0] ?? null, 'source PDF success case');
        $request = self::object($success['request'] ?? null, 'source PDF request');
        $response = self::object($success['response'] ?? null, 'source PDF response');
        $sourceCanonicalSha256 = Rt3ReadEvidenceContract::canonicalSha256($source);

        if (self::string($source, 'capability', 'source read capability') !== 'invoice.pdf.stream'
            || ! \hash_equals(
                self::string($remotePdf, 'source_read_canonical_sha256', 'source read canonical SHA-256'),
                $sourceCanonicalSha256,
            )
            || ($source['provider'] ?? null) !== ($document['provider'] ?? null)
            || ($source['harness'] ?? null) !== ($document['harness'] ?? null)
            || ($source['run'] ?? null) !== ($document['run'] ?? null)
            || self::string($success, 'id', 'source PDF case ID') !== 'success'
            || ! \hash_equals(
                self::string($remotePdf, 'remote_invoice_id_hmac_sha256', 'remote invoice ID HMAC'),
                self::string($request, 'remote_identity_hmac_sha256', 'source remote identity HMAC'),
            )
            || ! \hash_equals(
                self::string($remotePdf, 'remote_snapshot_hmac_sha256', 'remote snapshot HMAC'),
                self::string($request, 'remote_snapshot_hmac_sha256', 'source remote snapshot HMAC'),
            )
            || ! \hash_equals(
                self::string($remotePdf, 'ksef_terminal_operation_hmac_sha256', 'KSeF terminal operation HMAC'),
                self::string($request, 'terminal_operation_hmac_sha256', 'source terminal operation HMAC'),
            )
            || self::string($success, 'observed_at', 'source PDF observation time') !== self::string($remotePdf, 'observed_at', 'remote PDF observation time')
            || self::string($response, 'content_type', 'source PDF content type') !== self::string($remotePdf, 'content_type', 'remote PDF content type')
            || self::integer($response, 'content_length', 'source PDF byte count') !== self::integer($remotePdf, 'bytes', 'remote PDF byte count')
            || ! \hash_equals(
                self::string($response, 'body_sha256', 'source PDF SHA-256'),
                self::string($remotePdf, 'sha256', 'remote PDF SHA-256'),
            )) {
            throw new InvalidArgumentException('The RT-6 evidence is not bound to its canonical RT-3 PDF source.');
        }
    }

    /**
     * @param  array<string, mixed>  $remotePdf
     * @param  array<string, mixed>  $run
     */
    private static function assertRemotePdf(
        #[SensitiveParameter] array $remotePdf,
        #[SensitiveParameter] array $run,
    ): void {
        self::assertExactKeys($remotePdf, [
            'remote_invoice_id_hmac_sha256',
            'remote_snapshot_hmac_sha256',
            'ksef_terminal_operation_hmac_sha256',
            'source_read_evidence_contract',
            'source_read_evidence_version',
            'source_read_capability',
            'source_read_canonical_sha256',
            'observed_at',
            'rendering_profile',
            'content_type',
            'bytes',
            'sha256',
            'validation',
        ], 'RT-6 remote PDF');

        foreach (['remote_invoice_id_hmac_sha256', 'remote_snapshot_hmac_sha256', 'ksef_terminal_operation_hmac_sha256', 'source_read_canonical_sha256', 'sha256'] as $field) {
            self::assertSha256(self::string($remotePdf, $field, $field), $field);
        }

        if (self::string($remotePdf, 'source_read_evidence_contract', 'source read evidence contract') !== Rt3ReadEvidenceContract::Contract
            || self::string($remotePdf, 'source_read_evidence_version', 'source read evidence version') !== Rt3ReadEvidenceContract::Version
            || self::string($remotePdf, 'source_read_capability', 'source read capability') !== 'invoice.pdf.stream') {
            throw new InvalidArgumentException('The RT-6 source read evidence contract is invalid.');
        }

        self::assertInstantWithinRun(self::string($remotePdf, 'observed_at', 'remote PDF observation time'), $run, 'remote PDF observation time');
        if (self::string($remotePdf, 'rendering_profile', 'rendering profile') !== 'default_pdf') {
            throw new InvalidArgumentException('The RT-6 rendering profile is invalid.');
        }
        $bytes = self::integer($remotePdf, 'bytes', 'remote PDF byte count');

        if (self::string($remotePdf, 'content_type', 'remote PDF content type') !== 'application/pdf'
            || self::string($remotePdf, 'validation', 'remote PDF validation') !== 'pdf_magic_and_trailer_valid'
            || $bytes < 1_024
            || $bytes > 52_428_800) {
            throw new InvalidArgumentException('The RT-6 remote PDF contract is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $remotePdf
     * @param  array<string, mixed>  $run
     */
    private static function assertArtifact(
        #[SensitiveParameter] array $artifact,
        #[SensitiveParameter] array $remotePdf,
        #[SensitiveParameter] array $run,
    ): void {
        self::assertExactKeys($artifact, [
            'content_address',
            'object_sha256',
            'object_bytes',
            'descriptor_sha256',
            'storage_namespace_hmac_sha256',
            'projection_status',
            'encryption_contract',
            'ciphertext_sha256',
            'retention_policy_sha256',
            'purge_authority_policy_sha256',
            'purge_permit_evidence_sha256',
            'database_lock_scope_hmac_sha256',
            'schema_search_path_hmac_sha256',
            'repository_connection_hmac_sha256',
            'writer_connection_hmac_sha256',
            'lease_receipt_sha256',
            'generation',
            'persisted_at',
            'checked_at',
        ], 'RT-6 artifact');

        foreach ([
            'object_sha256',
            'descriptor_sha256',
            'storage_namespace_hmac_sha256',
            'ciphertext_sha256',
            'retention_policy_sha256',
            'purge_authority_policy_sha256',
            'purge_permit_evidence_sha256',
            'database_lock_scope_hmac_sha256',
            'schema_search_path_hmac_sha256',
            'repository_connection_hmac_sha256',
            'writer_connection_hmac_sha256',
            'lease_receipt_sha256',
        ] as $field) {
            self::assertSha256(self::string($artifact, $field, $field), $field);
        }

        $remoteSha256 = self::string($remotePdf, 'sha256', 'remote PDF SHA-256');
        $objectSha256 = self::string($artifact, 'object_sha256', 'object SHA-256');
        $remoteBytes = self::integer($remotePdf, 'bytes', 'remote PDF bytes');
        $objectBytes = self::integer($artifact, 'object_bytes', 'object bytes');
        $repositoryConnection = self::string($artifact, 'repository_connection_hmac_sha256', 'repository connection');
        $writerConnection = self::string($artifact, 'writer_connection_hmac_sha256', 'writer connection');
        $generation = self::integer($artifact, 'generation', 'artifact generation');
        $persistedAtValue = self::string($artifact, 'persisted_at', 'artifact persistence time');
        $checkedAtValue = self::string($artifact, 'checked_at', 'artifact check time');
        self::assertInstantWithinRun($persistedAtValue, $run, 'artifact persistence time');
        self::assertInstantWithinRun($checkedAtValue, $run, 'artifact check time');
        $observedAt = self::strictUtcMicrosecondInstant(self::string($remotePdf, 'observed_at', 'remote PDF observation time'), 'remote PDF observation time');
        $persistedAt = self::strictUtcMicrosecondInstant($persistedAtValue, 'artifact persistence time');
        $checkedAt = self::strictUtcMicrosecondInstant($checkedAtValue, 'artifact check time');

        if (! \hash_equals($remoteSha256, $objectSha256)
            || self::string($artifact, 'content_address', 'content address') !== 'sha256:'.$objectSha256
            || $remoteBytes !== $objectBytes
            || self::string($artifact, 'projection_status', 'projection status') !== 'atomic_committed'
            || self::string($artifact, 'encryption_contract', 'encryption contract') !== 'database_bound_aes_256_gcm'
            || ! \hash_equals($repositoryConnection, $writerConnection)
            || $generation < 1
            || $generation > 1_000_000_000
            || self::microseconds($observedAt) > self::microseconds($persistedAt)
            || self::microseconds($persistedAt) > self::microseconds($checkedAt)) {
            throw new InvalidArgumentException('The RT-6 artifact projection or lock binding is invalid.');
        }
    }

    /** @param array<string, mixed> $checks */
    private static function assertChecks(#[SensitiveParameter] array $checks): void
    {
        self::assertExactKeys($checks, [...self::RequiredBooleanChecks, 'crash_points_tested', 'doctor_pages_scanned', 'retention_boundary_cases'], 'RT-6 checks');

        foreach (self::RequiredBooleanChecks as $field) {
            if (! self::boolean($checks, $field, $field)) {
                throw new InvalidArgumentException("The RT-6 {$field} proof must pass.");
            }
        }

        $crashPoints = self::integer($checks, 'crash_points_tested', 'crash-point count');
        $doctorPages = self::integer($checks, 'doctor_pages_scanned', 'doctor page count');
        $retentionBoundaries = self::integer($checks, 'retention_boundary_cases', 'retention boundary count');

        if ($crashPoints < 3 || $crashPoints > 128
            || $doctorPages < 1 || $doctorPages > 10_000
            || $retentionBoundaries < 3 || $retentionBoundaries > 128) {
            throw new InvalidArgumentException('The RT-6 proof coverage counts are invalid.');
        }
    }
}
