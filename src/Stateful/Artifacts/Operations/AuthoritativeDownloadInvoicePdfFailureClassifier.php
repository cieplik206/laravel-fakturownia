<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class AuthoritativeDownloadInvoicePdfFailureClassifier implements AuthoritativeFailureClassifier
{
    public function classify(OperationView $operation, Throwable $failure): ClassifiedFailure
    {
        if ($failure instanceof DownloadInvoicePdfOperationFailure) {
            return new ClassifiedFailure(
                $failure->disposition,
                $failure->safeFailure,
                $failure->reconciliationTrigger,
            );
        }

        return new ClassifiedFailure(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_invoice_pdf_unclassified_failure',
                'The invoice PDF operation failed without sufficient safe retry evidence.',
            ),
        );
    }
}
