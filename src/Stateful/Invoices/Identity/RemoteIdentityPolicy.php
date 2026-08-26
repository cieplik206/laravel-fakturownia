<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Identity;

enum RemoteIdentityPolicy: string
{
    case BusinessOid = 'business_oid';
    case TechnicalOidWithTransactionOrder = 'technical_oid_with_transaction_order';
    case NoRemoteUniqueness = 'no_remote_uniqueness';
}
