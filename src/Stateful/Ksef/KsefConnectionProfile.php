<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

use InvalidArgumentException;
use LogicException;

final readonly class KsefConnectionProfile
{
    public function __construct(
        public string $connectionFingerprintSha256,
        public KsefOwnership $ownership,
        public KsefValidationMode $validationMode,
        public ?string $expectedGovAutoSendMode,
        public ?bool $expectedBuyerCompany,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $connectionFingerprintSha256) !== 1) {
            throw new InvalidArgumentException('A KSeF connection profile requires an exact connection fingerprint.');
        }

        if ($ownership === KsefOwnership::ExplicitSdk
            && ($expectedGovAutoSendMode !== null || $expectedBuyerCompany !== null)) {
            throw new InvalidArgumentException('Explicit SDK ownership requires disabled provider auto-send settings.');
        }

        if ($ownership === KsefOwnership::ProviderAutoSend
            && (! is_string($expectedGovAutoSendMode)
                || $expectedGovAutoSendMode === ''
                || $expectedGovAutoSendMode !== trim($expectedGovAutoSendMode)
                || strlen($expectedGovAutoSendMode) > 128
                || $expectedBuyerCompany === null)) {
            throw new InvalidArgumentException('Provider auto-send ownership requires exact account expectations.');
        }
    }

    public static function explicitSdk(
        string $connectionFingerprintSha256,
        KsefValidationMode $validationMode,
    ): self {
        return new self(
            $connectionFingerprintSha256,
            KsefOwnership::ExplicitSdk,
            $validationMode,
            null,
            null,
        );
    }

    public static function providerAutoSend(
        string $connectionFingerprintSha256,
        KsefValidationMode $validationMode,
        string $expectedGovAutoSendMode,
        bool $expectedBuyerCompany,
    ): self {
        return new self(
            $connectionFingerprintSha256,
            KsefOwnership::ProviderAutoSend,
            $validationMode,
            $expectedGovAutoSendMode,
            $expectedBuyerCompany,
        );
    }

    public function expectedValidateInvoicesForGov(): bool
    {
        return $this->validationMode === KsefValidationMode::BlockInvalid;
    }

    public function isInitialPilotProfile(): bool
    {
        return $this->ownership === KsefOwnership::ExplicitSdk
            && $this->validationMode === KsefValidationMode::BlockInvalid;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('KSeF connection profiles cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('KSeF connection profiles cannot be unserialized.');
    }
}
