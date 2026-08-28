<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Workflow;

use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

final readonly class AttachmentFinalizeProjectionPlan
{
    public const string TargetId = 'fakturownia.attachment_finalize';

    public const int SchemaVersion = 1;

    public function __construct(
        public OperationId $uploadOperationId,
        public OperationId $finalizeOperationId,
    ) {}
}
