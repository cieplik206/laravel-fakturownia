<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class DeleteCostInvoiceFailureClassifier implements FailureClassifier
{
    public function classify(OperationView $operation, Throwable $failure): FailureClassification
    {
        if ($failure instanceof DeleteCostInvoiceOperationFailure) {
            return new FailureClassification($failure->disposition, $failure->safeFailure);
        }

        return new FailureClassification(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_cost_invoice_delete_unclassified_failure',
                'The cost invoice delete failed and requires an audited operator review.',
            ),
        );
    }
}
