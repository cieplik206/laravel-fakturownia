<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;

final readonly class UploadAttachmentBinaryOperationFactory
{
    public const string OperationType = 'fakturownia.invoice.attachment.binary.upload';

    public const string SemanticSlot = 'attachment-upload';

    public function make(
        UploadAttachmentBinaryCommand $command,
        IntegrationContext $context,
        int $priority = 0,
    ): AcceptOperation {
        return new AcceptOperation(
            scope: IntegrationScope::of('fakturownia', $command->connectionKey->value),
            operationType: new OperationType(self::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            intent: new IntentIdentity(
                resourceType: 'invoice',
                semanticSlot: self::SemanticSlot,
                localReference: new LocalReference('invoice_attachment_upload', $command->localReference),
            ),
            payload: (new UploadAttachmentBinaryPayloadCodec)->encode($command),
            context: $context,
            priority: $priority,
        );
    }
}
