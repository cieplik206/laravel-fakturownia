<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class IssueCorrectionFailureClassifier implements FailureClassifier
{
    public function classify(OperationView $operation, Throwable $failure): FailureClassification
    {
        if ($failure instanceof IssueCorrectionOperationFailure) {
            return new FailureClassification($failure->disposition, $failure->safeFailure);
        }

        return new FailureClassification(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_correction_unclassified_failure',
                'The correction operation failed without sufficient safe retry evidence.',
            ),
        );
    }
}
