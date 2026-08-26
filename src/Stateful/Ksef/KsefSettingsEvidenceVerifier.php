<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final class KsefSettingsEvidenceVerifier implements JsonSerializable
{
    private SensitiveParameterValue $hmacKey;

    public function __construct(#[SensitiveParameter] string $hmacKey)
    {
        if (strlen($hmacKey) < 32 || strlen($hmacKey) > 4096) {
            throw new InvalidArgumentException('The KSeF evidence HMAC key must contain between 32 and 4096 bytes.');
        }

        $this->hmacKey = new SensitiveParameterValue($hmacKey);
    }

    public function attestOperator(
        string $connectionFingerprintSha256,
        KsefAccountSetting $setting,
        string|bool|null $value,
    ): KsefSettingObservation {
        $observedAt = self::now();
        $validUntil = $observedAt->modify(sprintf(
            '+%d seconds',
            KsefSettingEvidenceSource::OperatorAttested->maximumTtlSeconds(),
        ));

        $unsigned = KsefSettingObservation::fromSignedEvidence(
            $connectionFingerprintSha256,
            $setting,
            $value,
            KsefSettingEvidenceSource::OperatorAttested,
            $observedAt,
            $validUntil,
            str_repeat('0', 64),
        );

        return KsefSettingObservation::fromSignedEvidence(
            $connectionFingerprintSha256,
            $setting,
            $value,
            KsefSettingEvidenceSource::OperatorAttested,
            $observedAt,
            $validUntil,
            $this->sign($unsigned->evidencePayload()),
        );
    }

    public function verifies(KsefSettingObservation $observation): bool
    {
        return $observation->isFreshAt(self::now())
            && hash_equals(
                $this->sign($observation->evidencePayload()),
                $observation->evidenceMacSha256,
            );
    }

    /** @return array{hmac_key: string} */
    public function __debugInfo(): array
    {
        return ['hmac_key' => '[REDACTED]'];
    }

    /** @return array{hmac_key: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('KSeF evidence verifiers cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('KSeF evidence verifiers cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('KSeF evidence verifiers cannot be unserialized.');
    }

    private function sign(string $payload): string
    {
        $hmacKey = $this->hmacKey->getValue();

        if (! is_string($hmacKey)) {
            throw new LogicException('The KSeF evidence HMAC key is corrupted.');
        }

        return hash_hmac('sha256', $payload, $hmacKey);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
