<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Operations;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use InvalidArgumentException;

final readonly class IssueCostInvoiceOperationFactory
{
    public const string OperationType = 'fakturownia.invoice.cost.issue';

    public const string SemanticSlot = 'cost';

    public function make(
        IssueCostInvoiceCommand $command,
        IntegrationContext $context,
        int $priority = 0,
    ): AcceptOperation {
        $localReference = $command->identity->transactionOrderReference();

        if ($localReference === null) {
            throw new InvalidArgumentException('The cost invoice operation requires a stable local cost reference.');
        }

        return new AcceptOperation(
            scope: IntegrationScope::of('fakturownia', $command->identity->scope->connection->value),
            operationType: new OperationType(self::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            intent: new IntentIdentity(
                resourceType: 'invoice',
                semanticSlot: self::SemanticSlot,
                localReference: new LocalReference('cost_invoice', $localReference),
            ),
            payload: (new IssueCostInvoicePayloadCodec)->encode($command),
            context: $context,
            priority: $priority,
        );
    }
}
