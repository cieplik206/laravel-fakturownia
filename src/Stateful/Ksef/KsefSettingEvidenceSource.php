<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

enum KsefSettingEvidenceSource: string
{
    case OnlineVerified = 'online_verified';
    case OperatorAttested = 'operator_attested';

    public function maximumTtlSeconds(): int
    {
        return match ($this) {
            self::OnlineVerified => 900,
            self::OperatorAttested => 600,
        };
    }
}
