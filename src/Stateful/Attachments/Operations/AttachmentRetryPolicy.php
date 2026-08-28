<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Operations;

use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;

final readonly class AttachmentRetryPolicy implements RetryPolicy
{
    public function decide(OperationView $operation, FailureClassification $failure): RetryInstruction
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
