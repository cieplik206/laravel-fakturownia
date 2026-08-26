<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance\Contracts\ArtifactMaintenanceStore;
use Throwable;

final readonly class ArtifactIntegrityVerifier
{
    private const int READ_BYTES = 65_536;

    public function __construct(private ArtifactMaintenanceStore $store) {}

    public function inspect(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): ArtifactObjectVerification {
        if (! $record->belongsTo($scope)) {
            return ArtifactObjectVerification::failed(ArtifactMaintenanceIssue::MetadataMismatch);
        }

        try {
            $observation = $this->store->inspectFinalized(
                $scope->storageNamespace,
                $record->object->contentAddress,
            );
        } catch (Throwable) {
            return ArtifactObjectVerification::failed(ArtifactMaintenanceIssue::ObjectUnreadable);
        }

        if ($observation === null) {
            return ArtifactObjectVerification::failed(ArtifactMaintenanceIssue::MissingObject);
        }

        if (! $this->metadataMatches($record, $observation)) {
            return ArtifactObjectVerification::failed(ArtifactMaintenanceIssue::MetadataMismatch);
        }

        $bytesIssue = $this->verifyBytes($scope, $record);

        if ($bytesIssue !== null) {
            return ArtifactObjectVerification::failed($bytesIssue);
        }

        return ArtifactObjectVerification::healthy($observation);
    }

    private function metadataMatches(
        ArtifactMaintenanceRecord $record,
        ArtifactObjectObservation $observation,
    ): bool {
        $expected = $record->object;
        $actual = $observation->object;

        return hash_equals($expected->disk, $actual->disk)
            && hash_equals((string) $expected->contentAddress, (string) $actual->contentAddress)
            && hash_equals($expected->mimeType, $actual->mimeType)
            && $expected->sizeBytes === $actual->sizeBytes;
    }

    private function verifyBytes(
        ArtifactMaintenanceScope $scope,
        ArtifactMaintenanceRecord $record,
    ): ?ArtifactMaintenanceIssue {
        try {
            $stream = $this->store->openFinalized(
                $scope->storageNamespace,
                $record->object->contentAddress,
            );

            try {
                return $this->consume($stream, $record);
            } finally {
                $stream->close();
            }
        } catch (Throwable) {
            return ArtifactMaintenanceIssue::ObjectUnreadable;
        }
    }

    private function consume(
        ArtifactContentStream $stream,
        ArtifactMaintenanceRecord $record,
    ): ?ArtifactMaintenanceIssue {
        $hash = hash_init('sha256');
        $size = 0;

        while (! $stream->eof()) {
            $chunk = $stream->read(self::READ_BYTES);

            if ($chunk === '') {
                return ArtifactMaintenanceIssue::ObjectUnreadable;
            }

            $size += strlen($chunk);

            if ($size > $record->object->sizeBytes) {
                return ArtifactMaintenanceIssue::SizeMismatch;
            }

            hash_update($hash, $chunk);
        }

        if ($size !== $record->object->sizeBytes) {
            return ArtifactMaintenanceIssue::SizeMismatch;
        }

        return hash_equals($record->object->contentSha256(), hash_final($hash))
            ? null
            : ArtifactMaintenanceIssue::ChecksumMismatch;
    }
}
