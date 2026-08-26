<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactStorageNamespace;
use JsonException;
use LogicException;

final class ArtifactPurgeClaims
{
    public static function orphan(
        ArtifactStorageNamespace $storageNamespace,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): string {
        return self::digest([
            'purpose' => ArtifactPurgePurpose::Orphan->value,
            'storage' => self::storage($storageNamespace),
            'observation' => self::observation($observation),
            'deadline' => self::deadline($deadline),
        ]);
    }

    public static function expired(
        ArtifactStorageNamespace $storageNamespace,
        ArtifactMaintenanceRecord $record,
        ArtifactObjectObservation $observation,
        ArtifactPurgeDeadline $deadline,
    ): string {
        return self::digest([
            'purpose' => ArtifactPurgePurpose::Expired->value,
            'storage' => self::storage($storageNamespace),
            'record' => [
                'id' => $record->id,
                'connection_key' => $record->connectionKey,
                'storage' => self::storage($record->storageNamespace),
                'content_address' => (string) $record->object->contentAddress,
                'status' => $record->status->value,
                'ready_at' => self::time($record->readyAt),
                'expires_at' => $record->expiresAt === null ? null : self::time($record->expiresAt),
            ],
            'observation' => self::observation($observation),
            'deadline' => self::deadline($deadline),
        ]);
    }

    /** @param array<string, mixed> $claims */
    private static function digest(array $claims): string
    {
        try {
            $json = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new LogicException('Artifact purge claims could not be encoded.', previous: $exception);
        }

        return hash('sha256', $json);
    }

    /** @return array{disk: string, prefix: string} */
    private static function storage(ArtifactStorageNamespace $storageNamespace): array
    {
        return [
            'disk' => $storageNamespace->disk,
            'prefix' => $storageNamespace->prefix,
        ];
    }

    /** @return array{disk: string, content_address: string, mime_type: string, size_bytes: int, last_modified_at: string, generation_fingerprint_sha256: string} */
    private static function observation(ArtifactObjectObservation $observation): array
    {
        return [
            'disk' => $observation->object->disk,
            'content_address' => (string) $observation->object->contentAddress,
            'mime_type' => $observation->object->mimeType,
            'size_bytes' => $observation->object->sizeBytes,
            'last_modified_at' => self::time($observation->lastModifiedAt),
            'generation_fingerprint_sha256' => $observation->generationFingerprintSha256,
        ];
    }

    /** @return array{issued_at: string, expires_at: string, maximum_duration_seconds: int} */
    private static function deadline(ArtifactPurgeDeadline $deadline): array
    {
        return [
            'issued_at' => self::time($deadline->issuedAt),
            'expires_at' => self::time($deadline->expiresAt),
            'maximum_duration_seconds' => $deadline->maximumDurationSeconds,
        ];
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.uP');
    }
}
