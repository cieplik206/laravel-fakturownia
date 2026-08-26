<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

enum KsefStatusCategory: string
{
    case NotSent = 'not_sent';
    case Succeeded = 'succeeded';
    case Processing = 'processing';
    case StatusCheckError = 'status_check_error';
    case TechnicalError = 'technical_error';
    case Offline = 'offline';
    case Duplicate = 'duplicate';
    case ConfigurationBlocked = 'configuration_blocked';
    case NotApplicable = 'not_applicable';
    case Rejected = 'rejected';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::NotApplicable, self::Rejected => true,
            default => false,
        };
    }
}
