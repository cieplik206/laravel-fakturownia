<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Workflow\Contracts;

use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AttachmentUploadProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\PendingAttachmentFinalize;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

interface AttachmentWorkflowStore
{
    public function applyUpload(AttachmentUploadProjectionPlan $plan): void;

    /** @return list<PendingAttachmentFinalize> */
    public function pendingFinalize(int $limit): array;

    public function linkFinalize(OperationId $uploadOperationId, OperationId $finalizeOperationId): void;

    public function markFinalized(OperationId $uploadOperationId, OperationId $finalizeOperationId): void;
}
