<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

final readonly class KsefDoctorPreflight
{
    public function __construct(private KsefSettingsEvidenceVerifier $evidenceVerifier) {}

    public function inspect(
        KsefConnectionProfile $profile,
        KsefAccountSettingsSnapshot $settings,
    ): KsefDoctorResult {
        return KsefDoctorResult::inspect($profile, $settings, $this->evidenceVerifier);
    }
}
