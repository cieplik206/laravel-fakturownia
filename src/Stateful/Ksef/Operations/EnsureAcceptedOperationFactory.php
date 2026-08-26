<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;

final readonly class EnsureAcceptedOperationFactory
{
    public function make(
        EnsureAcceptedCommand $command,
        IntegrationContext $context,
        int $priority = 0,
    ): AcceptOperation {
        return new AcceptOperation(
            scope: IntegrationScope::of('fakturownia', $command->connectionKey->value),
            operationType: new OperationType(EnsureAcceptedOperationDefinitionProvider::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            intent: new IntentIdentity(
                resourceType: 'invoice',
                semanticSlot: EnsureAcceptedOperationDefinitionProvider::SemanticSlot,
                localReference: new LocalReference(
                    EnsureAcceptedOperationDefinitionProvider::LocalReferenceType,
                    $command->resourceId->value,
                ),
            ),
            payload: (new EnsureAcceptedPayloadCodec)->encode($command),
            context: $context,
            priority: $priority,
        );
    }
}
