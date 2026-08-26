<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices\Operations;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use InvalidArgumentException;

final readonly class IssueInvoiceOperationFactory
{
    public function make(
        IssueInvoiceCommand $command,
        IntegrationContext $context,
        int $priority = 0,
    ): AcceptOperation {
        $localReference = $command->identity->transactionOrderReference();

        if ($localReference === null) {
            throw new InvalidArgumentException('The invoice operation requires a stable transaction-order reference.');
        }

        return new AcceptOperation(
            scope: IntegrationScope::of('fakturownia', $command->identity->scope->connection->value),
            operationType: new OperationType(IssueInvoiceOperationDefinitionProvider::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            intent: new IntentIdentity(
                resourceType: 'invoice',
                semanticSlot: IssueInvoiceOperationDefinitionProvider::SemanticSlot,
                localReference: new LocalReference('transaction_order', $localReference),
            ),
            payload: (new IssueInvoicePayloadCodec)->encode($command),
            context: $context,
            priority: $priority,
        );
    }
}
