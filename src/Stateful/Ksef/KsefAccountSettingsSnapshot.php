<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

use InvalidArgumentException;
use LogicException;

final readonly class KsefAccountSettingsSnapshot
{
    public function __construct(
        public KsefSettingObservation $govAutoSendMode,
        public KsefSettingObservation $validateInvoicesForGov,
        public KsefSettingObservation $buyerCompany,
    ) {
        if ($govAutoSendMode->setting !== KsefAccountSetting::GovAutoSendMode
            || $validateInvoicesForGov->setting !== KsefAccountSetting::ValidateInvoicesForGov
            || $buyerCompany->setting !== KsefAccountSetting::BuyerCompany) {
            throw new InvalidArgumentException('The KSeF account-settings snapshot is not canonical.');
        }

        if (! hash_equals($govAutoSendMode->connectionFingerprintSha256, $validateInvoicesForGov->connectionFingerprintSha256)
            || ! hash_equals($govAutoSendMode->connectionFingerprintSha256, $buyerCompany->connectionFingerprintSha256)) {
            throw new InvalidArgumentException('The KSeF account-settings snapshot mixes connection scopes.');
        }
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('KSeF account-settings snapshots cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('KSeF account-settings snapshots cannot be unserialized.');
    }
}
