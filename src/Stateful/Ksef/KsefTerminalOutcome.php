<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

enum KsefTerminalOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case NotApplicable = 'not_applicable';
}
