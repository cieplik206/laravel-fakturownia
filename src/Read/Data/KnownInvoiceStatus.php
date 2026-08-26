<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

enum KnownInvoiceStatus: string
{
    case Issued = 'issued';
    case Sent = 'sent';
    case Paid = 'paid';
    case Partial = 'partial';
    case Rejected = 'rejected';
}
