<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;

final readonly class DeleteCostInvoiceOperationFactory
{
    public const string OperationType = 'fakturownia.invoice.cost.delete';

    public const string SemanticSlot = 'delete';

    public function make(
        DeleteCostInvoiceCommand $command,
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
                localReference: new LocalReference('cost_invoice_delete', $command->localReference),
            ),
            payload: (new DeleteCostInvoicePayloadCodec)->encode($command),
            context: $context,
            priority: $priority,
        );
    }
}
