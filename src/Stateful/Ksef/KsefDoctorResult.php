<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

final readonly class KsefDoctorResult
{
    /** @var list<KsefDoctorIssue> */
    public array $issues;

    /** @param array<mixed> $issues */
    private function __construct(
        public KsefConnectionProfile $profile,
        array $issues,
        private DateTimeImmutable $validUntil,
    ) {
        foreach ($issues as $issue) {
            if (! $issue instanceof KsefDoctorIssue) {
                throw new InvalidArgumentException('The KSeF doctor result contains an invalid issue.');
            }
        }

        $this->issues = array_values(array_unique($issues, SORT_REGULAR));
    }

    public static function inspect(
        KsefConnectionProfile $profile,
        KsefAccountSettingsSnapshot $settings,
        KsefSettingsEvidenceVerifier $evidenceVerifier,
    ): self {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $issues = [];
        $validUntil = $settings->govAutoSendMode->validUntil;

        if (! self::hasCanonicalSettingsSlots($settings)) {
            $issues[] = KsefDoctorIssue::EvidenceUnverified;
        }

        foreach ([$settings->govAutoSendMode, $settings->validateInvoicesForGov, $settings->buyerCompany] as $setting) {
            if (! hash_equals($profile->connectionFingerprintSha256, $setting->connectionFingerprintSha256)) {
                $issues[] = KsefDoctorIssue::EvidenceScopeMismatch;
            }

            if (! $evidenceVerifier->verifies($setting)) {
                $issues[] = KsefDoctorIssue::EvidenceUnverified;
            }

            if (! $setting->isFreshAt($now)) {
                $issues[] = KsefDoctorIssue::EvidenceExpired;
            }

            if ($setting->validUntil < $validUntil) {
                $validUntil = $setting->validUntil;
            }
        }

        if ($settings->govAutoSendMode->value !== $profile->expectedGovAutoSendMode) {
            $issues[] = KsefDoctorIssue::GovAutoSendModeMismatch;
        }

        if ($settings->validateInvoicesForGov->value !== $profile->expectedValidateInvoicesForGov()) {
            $issues[] = KsefDoctorIssue::ValidateInvoicesForGovMismatch;
        }

        if ($profile->ownership === KsefOwnership::ProviderAutoSend
            && $settings->buyerCompany->value !== $profile->expectedBuyerCompany) {
            $issues[] = KsefDoctorIssue::BuyerCompanyMismatch;
        }

        if ($profile->ownership === KsefOwnership::ExplicitSdk && ! $profile->isInitialPilotProfile()) {
            $issues[] = KsefDoctorIssue::PilotProfileUnsupported;
        }

        return new self($profile, $issues, $validUntil);
    }

    public function passes(): bool
    {
        return $this->issues === []
            && $this->validUntil > new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function permitsExplicitSend(): bool
    {
        return false;
    }

    public function permitsObservation(): bool
    {
        return $this->passes();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('KSeF doctor results cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('KSeF doctor results cannot be unserialized.');
    }

    private static function hasCanonicalSettingsSlots(KsefAccountSettingsSnapshot $settings): bool
    {
        return $settings->govAutoSendMode->setting === KsefAccountSetting::GovAutoSendMode
            && $settings->validateInvoicesForGov->setting === KsefAccountSetting::ValidateInvoicesForGov
            && $settings->buyerCompany->setting === KsefAccountSetting::BuyerCompany
            && hash_equals(
                $settings->govAutoSendMode->connectionFingerprintSha256,
                $settings->validateInvoicesForGov->connectionFingerprintSha256,
            )
            && hash_equals(
                $settings->govAutoSendMode->connectionFingerprintSha256,
                $settings->buyerCompany->connectionFingerprintSha256,
            );
    }
}
