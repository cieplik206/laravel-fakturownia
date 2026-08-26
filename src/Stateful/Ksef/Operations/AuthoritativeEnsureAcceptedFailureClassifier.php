<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class AuthoritativeEnsureAcceptedFailureClassifier implements AuthoritativeFailureClassifier
{
    public function classify(OperationView $operation, Throwable $failure): ClassifiedFailure
    {
        if ($failure instanceof EnsureAcceptedOperationFailure) {
            return new ClassifiedFailure(
                $failure->disposition,
                $failure->safeFailure,
                $failure->reconciliationTrigger,
            );
        }

        return new ClassifiedFailure(
            FailureDisposition::ManualReview,
            new SafeOperationFailure(
                'fakturownia_ksef_unclassified_failure',
                'The KSeF operation failed without evidence permitting another send.',
            ),
        );
    }
}
