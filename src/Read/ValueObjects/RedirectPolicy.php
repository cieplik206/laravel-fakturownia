<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\ValueObjects;

enum RedirectPolicy: string
{
    case Deny = 'deny';
    case SameHost = 'same_host';
    case CrossHostWithoutCredentials = 'cross_host_without_credentials';
}
