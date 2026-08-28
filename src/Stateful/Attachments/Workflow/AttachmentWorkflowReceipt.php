<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Workflow;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;

final readonly class AttachmentWorkflowReceipt
{
    use RejectsNativeSerialization;

    public function __construct(public OperationReceipt $uploadReceipt) {}

    public function workflowId(): string
    {
        return $this->uploadReceipt->operationId->value;
    }
}
