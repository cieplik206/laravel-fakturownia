<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Registry\ManagedMutationIdentityContract;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

final class EnsureAcceptedOperationDefinitionProvider implements OperationDefinitionProvider
{
    public const string OperationType = 'fakturownia.invoice.ksef.ensure_accepted';

    public const string SemanticSlot = 'ksef.ensure_accepted';

    public const string LocalReferenceType = 'invoice_resource';

    public static function provider(): ProviderKey
    {
        return new ProviderKey('fakturownia');
    }

    public static function definitions(): iterable
    {
        yield OperationDefinition::singleEffect(
            provider: self::provider(),
            operationType: new OperationType(self::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'invoice',
                localReferenceType: self::LocalReferenceType,
                semanticSlots: [self::SemanticSlot],
            ),
            handler: new ServiceReference(EnsureAcceptedOperationHandler::class, OperationHandler::class),
            failureClassifier: new ServiceReference(EnsureAcceptedFailureClassifier::class, FailureClassifier::class),
            retryPolicy: new ServiceReference(EnsureAcceptedRetryPolicy::class, RetryPolicy::class),
            reconciliationStrategy: new ServiceReference(
                EnsureAcceptedReconciliationStrategy::class,
                ReconciliationStrategy::class,
            ),
            resultCodec: new ServiceReference(EnsureAcceptedResultCodec::class, OperationResultCodec::class),
            outcomeProjector: new ServiceReference(EnsureAcceptedOutcomeProjector::class, OutcomeProjector::class),
        );
    }
}
