<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

enum InvoiceValidationProfile: string
{
    case Standard = 'standard';
    case KsefStrict = 'ksef_strict';
    case KsefAdvisory = 'ksef_advisory';

    public function usesKsefConstraints(): bool
    {
        return $this !== self::Standard;
    }

    public function rejectsIssues(): bool
    {
        return $this !== self::KsefAdvisory;
    }
}
