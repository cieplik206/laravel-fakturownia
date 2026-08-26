<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;

final readonly class AuthoritativeIssueInvoiceRetryPolicy implements AuthoritativeRetryPolicy
{
    public function decide(OperationView $operation, ClassifiedFailure $failure): RetryInstruction
    {
        return match ($failure->disposition) {
            FailureDisposition::RequestNotStarted => RetryInstruction::retry(),
            FailureDisposition::Uncertain => RetryInstruction::reconcile(),
            FailureDisposition::NotApplied,
            FailureDisposition::Permanent => RetryInstruction::fail(),
            FailureDisposition::RetryableRead,
            FailureDisposition::ManualReview => RetryInstruction::manualReview(),
        };
    }
}
