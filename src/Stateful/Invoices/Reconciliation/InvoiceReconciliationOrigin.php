<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Reconciliation;

enum InvoiceReconciliationOrigin: string
{
    case LostResponse = 'lost_response';
    case DuplicateEnvelope = 'duplicate_envelope';
    case OidConflict = 'oid_conflict';
    case Unclassified = 'unclassified';

    public function allowsConclusiveAbsence(): bool
    {
        return match ($this) {
            self::LostResponse => true,
            self::DuplicateEnvelope,
            self::OidConflict,
            self::Unclassified => false,
        };
    }
}
