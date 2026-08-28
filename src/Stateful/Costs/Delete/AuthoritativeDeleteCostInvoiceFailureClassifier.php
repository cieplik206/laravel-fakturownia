<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class AuthoritativeDeleteCostInvoiceFailureClassifier implements AuthoritativeFailureClassifier
{
    public function classify(OperationView $operation, Throwable $failure): ClassifiedFailure
    {
        if ($failure instanceof DeleteCostInvoiceOperationFailure) {
            return new ClassifiedFailure($failure->disposition, $failure->safeFailure);
        }

        return new ClassifiedFailure(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_cost_invoice_delete_unclassified_failure',
                'The cost invoice delete failed and requires an audited operator review.',
            ),
        );
    }
}
