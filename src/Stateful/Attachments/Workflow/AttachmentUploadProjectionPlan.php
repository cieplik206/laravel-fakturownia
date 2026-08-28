<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Workflow;

use Cieplik206\Fakturownia\Stateful\Attachments\Upload\AttachmentBinaryUploadedResult;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

final readonly class AttachmentUploadProjectionPlan
{
    public const string TargetId = 'fakturownia.attachment_upload';

    public const int SchemaVersion = 1;

    public function __construct(
        public ConnectionKey $connectionKey,
        public OperationId $uploadOperationId,
        public AttachmentBinaryUploadedResult $result,
        public IntegrationContext $context,
    ) {}
}
