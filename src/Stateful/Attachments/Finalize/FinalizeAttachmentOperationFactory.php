<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;

final readonly class FinalizeAttachmentOperationFactory
{
    public const string OperationType = 'fakturownia.invoice.attachment.finalize';

    public const string SemanticSlot = 'attachment-finalize';

    public function make(FinalizeAttachmentCommand $command, IntegrationContext $context, int $priority = 0): AcceptOperation
    {
        return new AcceptOperation(
            scope: IntegrationScope::of('fakturownia', $command->connectionKey->value),
            operationType: new OperationType(self::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            intent: new IntentIdentity(
                resourceType: 'invoice',
                semanticSlot: self::SemanticSlot,
                localReference: new LocalReference('invoice_attachment_finalize', $command->uploadOperationId->value),
            ),
            payload: (new FinalizeAttachmentPayloadCodec)->encode($command),
            context: $context,
            priority: $priority,
        );
    }
}
