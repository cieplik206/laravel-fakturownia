<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;

final readonly class ChangeCostInvoiceStatusOperationFactory
{
    public const string OperationType = 'fakturownia.invoice.cost.status.change';

    public const string SemanticSlot = 'status';

    public function make(
        ChangeCostInvoiceStatusCommand $command,
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
                localReference: new LocalReference('cost_invoice_status_change', $command->localReference),
            ),
            payload: (new ChangeCostInvoiceStatusPayloadCodec)->encode($command),
            context: $context,
            priority: $priority,
        );
    }
}
