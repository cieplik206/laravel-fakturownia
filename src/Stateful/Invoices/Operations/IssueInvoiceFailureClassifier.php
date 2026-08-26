<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class IssueInvoiceFailureClassifier implements FailureClassifier
{
    public function classify(OperationView $operation, Throwable $failure): FailureClassification
    {
        if ($failure instanceof IssueInvoiceOperationFailure) {
            return new FailureClassification($failure->disposition, $failure->safeFailure);
        }

        return new FailureClassification(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_invoice_unclassified_failure',
                'The invoice operation failed without sufficient safe retry evidence.',
            ),
        );
    }
}
