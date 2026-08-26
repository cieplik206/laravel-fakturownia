<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

enum KnownInvoiceKind: string
{
    case Vat = 'vat';
    case Proforma = 'proforma';
    case Correction = 'correction';
    case Receipt = 'receipt';
    case Advance = 'advance';
    case Final = 'final';
    case Estimate = 'estimate';
    case Invoice = 'invoice';
}
