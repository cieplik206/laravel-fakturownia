<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\RemoteInvoiceIdentity;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class IssueCorrectionCommand
{
    use RejectsNativeSerialization;

    public function __construct(
        public CorrectionDraft $draft,
        public RemoteInvoiceIdentity $identity,
    ) {
        if ($identity->scope->documentKind !== 'correction'
            || $identity->scope->departmentId !== (string) $draft->departmentId
            || $identity->transactionOrderReference() === null
            || $draft->issueDate === null) {
            throw new InvalidArgumentException('Correction identity must match the draft and carry a local return reference.');
        }
    }
}
