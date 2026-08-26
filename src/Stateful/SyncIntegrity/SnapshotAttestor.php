<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\SyncIntegrity;

use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use InvalidArgumentException;
use ReflectionReference;
use SensitiveParameter;

final readonly class SnapshotAttestor
{
    private const string Protocol = 'cieplik206.fakturownia.sync-integrity.v1';

    private const int MaximumSnapshotDepth = 32;

    private const int MaximumSnapshotNodes = 10_000;

    private const int MaximumSnapshotAggregateBytes = 262_144;

    private const int MaximumSnapshotStringBytes = 131_072;

    private const int MaximumSnapshotKeyBytes = 191;

    private const int MaximumSnapshotCanonicalBytes = 524_288;

    public function __construct(
        private LookupHmacKeyRing $keyRing,
        private CanonicalJsonV1 $canonicalJson,
    ) {}

    public function attest(
        SyncIntegrityScope $scope,
        #[SensitiveParameter] string $remoteIdentity,
        #[SensitiveParameter] mixed $snapshot,
        ?int $keyVersion = null,
    ): SnapshotAttestation {
        $this->assertRemoteIdentity($remoteIdentity);

        $version = $keyVersion ?? $this->keyRing->activeVersion();

        if (! in_array($version, $this->keyRing->readableVersions(), true)) {
            throw new InvalidArgumentException('The requested snapshot HMAC key version is not readable.');
        }

        $nodes = 0;
        $aggregateBytes = 0;
        $this->assertBoundedSnapshot($snapshot, 0, $nodes, $aggregateBytes);

        $identityMaterial = $this->canonicalJson->encode([
            ...$scope->canonical(),
            'remote_identity' => $remoteIdentity,
        ]);
        $identityHmac = $this->digest($version, 'identity', $identityMaterial);
        $snapshotMaterial = $this->canonicalJson->encode([
            ...$scope->canonical(),
            'remote_identity_hmac' => $identityHmac->hex,
            'snapshot' => $snapshot,
        ]);

        if (strlen($snapshotMaterial) > self::MaximumSnapshotCanonicalBytes) {
            throw new InvalidArgumentException('The canonical snapshot exceeds the byte limit.');
        }

        return new SnapshotAttestation(
            scope: $scope,
            remoteIdentity: $identityHmac,
            snapshot: $this->digest($version, 'snapshot', $snapshotMaterial),
        );
    }

    public function matchesRemoteIdentity(
        SyncIntegrityScope $scope,
        #[SensitiveParameter] string $remoteIdentity,
        SnapshotHmac $expected,
    ): bool {
        $this->assertRemoteIdentity($remoteIdentity);

        if (! in_array($expected->keyVersion, $this->keyRing->readableVersions(), true)) {
            return false;
        }

        $identityMaterial = $this->canonicalJson->encode([
            ...$scope->canonical(),
            'remote_identity' => $remoteIdentity,
        ]);

        return $this->digest($expected->keyVersion, 'identity', $identityMaterial)->equals($expected);
    }

    private function digest(int $keyVersion, string $purpose, string $material): SnapshotHmac
    {
        $domainSeparated = self::Protocol."\0{$purpose}\0{$material}";
        $hex = $this->keyRing->hmacSha256($keyVersion, $domainSeparated);

        return new SnapshotHmac($keyVersion, $hex);
    }

    private function assertRemoteIdentity(#[SensitiveParameter] string $remoteIdentity): void
    {
        if ($remoteIdentity === ''
            || strlen($remoteIdentity) > 512
            || preg_match('//u', $remoteIdentity) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $remoteIdentity) === 1) {
            throw new InvalidArgumentException('The remote snapshot identity is invalid.');
        }
    }

    private function assertBoundedSnapshot(
        mixed $value,
        int $depth,
        int &$nodes,
        int &$aggregateBytes,
    ): void {
        $nodes++;
        $aggregateBytes += 2;

        if ($depth > self::MaximumSnapshotDepth
            || $nodes > self::MaximumSnapshotNodes
            || $aggregateBytes > self::MaximumSnapshotAggregateBytes) {
            throw new InvalidArgumentException('The snapshot exceeds its structural limits.');
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_string($value)) {
            $length = strlen($value);

            if ($length > self::MaximumSnapshotStringBytes
                || preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException('The snapshot contains an oversized or invalid string.');
            }

            $aggregateBytes += $length;

            if ($aggregateBytes > self::MaximumSnapshotAggregateBytes) {
                throw new InvalidArgumentException('The snapshot exceeds its structural limits.');
            }

            return;
        }

        if ($value instanceof CanonicalObject) {
            $value = $value->values;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('The snapshot may contain only canonical values.');
        }

        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (ReflectionReference::fromArrayElement($value, $key) !== null) {
                throw new InvalidArgumentException('The snapshot arrays must not contain references.');
            }

            if (! $isList && ! is_string($key)) {
                throw new InvalidArgumentException('The snapshot contains an invalid array.');
            }

            if (is_string($key)) {
                $keyLength = strlen($key);

                if ($keyLength > self::MaximumSnapshotKeyBytes
                    || preg_match('//u', $key) !== 1) {
                    throw new InvalidArgumentException('The snapshot contains an oversized or invalid key.');
                }

                $aggregateBytes += $keyLength;

                if ($aggregateBytes > self::MaximumSnapshotAggregateBytes) {
                    throw new InvalidArgumentException('The snapshot exceeds its structural limits.');
                }
            }

            $this->assertBoundedSnapshot($item, $depth + 1, $nodes, $aggregateBytes);
        }
    }
}
