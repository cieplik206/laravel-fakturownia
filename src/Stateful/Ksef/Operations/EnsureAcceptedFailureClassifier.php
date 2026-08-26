<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class EnsureAcceptedFailureClassifier implements FailureClassifier
{
    public function classify(OperationView $operation, Throwable $failure): FailureClassification
    {
        if ($failure instanceof EnsureAcceptedOperationFailure) {
            return new FailureClassification($failure->disposition, $failure->safeFailure);
        }

        return new FailureClassification(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_ksef_unclassified_failure',
                'The KSeF operation failed without evidence permitting another send.',
            ),
        );
    }
}
