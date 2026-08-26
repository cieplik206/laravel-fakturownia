<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use InvalidArgumentException;

final readonly class IssueCorrectionOperationFactory
{
    public function make(
        IssueCorrectionCommand $command,
        IntegrationContext $context,
        int $priority = 0,
    ): AcceptOperation {
        $localReference = $command->identity->transactionOrderReference();

        if ($localReference === null) {
            throw new InvalidArgumentException('The correction operation requires a stable local return reference.');
        }

        return new AcceptOperation(
            scope: IntegrationScope::of('fakturownia', $command->identity->scope->connection->value),
            operationType: new OperationType(IssueCorrectionOperationDefinitionProvider::OperationType),
            versions: new OperationDefinitionVersions(2, 1, 1),
            intent: new IntentIdentity(
                resourceType: 'invoice',
                semanticSlot: IssueCorrectionOperationDefinitionProvider::SemanticSlot,
                localReference: new LocalReference(
                    IssueCorrectionOperationDefinitionProvider::LocalReferenceType,
                    $localReference,
                ),
            ),
            payload: (new IssueCorrectionPayloadCodec)->encode($command),
            context: $context,
            priority: $priority,
        );
    }
}
