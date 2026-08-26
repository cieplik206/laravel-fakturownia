<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

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

final class IssueCorrectionOperationDefinitionProvider implements OperationDefinitionProvider
{
    public const string OperationType = 'fakturownia.invoice.correction.issue';

    public const string SemanticSlot = 'invoice.correction.issue';

    public const string LocalReferenceType = 'customer_return';

    public static function provider(): ProviderKey
    {
        return new ProviderKey('fakturownia');
    }

    public static function definitions(): iterable
    {
        yield OperationDefinition::singleEffect(
            provider: self::provider(),
            operationType: new OperationType(self::OperationType),
            versions: new OperationDefinitionVersions(2, 1, 1),
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'invoice',
                localReferenceType: self::LocalReferenceType,
                semanticSlots: [self::SemanticSlot],
            ),
            handler: new ServiceReference(IssueCorrectionOperationHandler::class, OperationHandler::class),
            failureClassifier: new ServiceReference(IssueCorrectionFailureClassifier::class, FailureClassifier::class),
            retryPolicy: new ServiceReference(IssueCorrectionRetryPolicy::class, RetryPolicy::class),
            reconciliationStrategy: new ServiceReference(
                IssueCorrectionReconciliationStrategy::class,
                ReconciliationStrategy::class,
            ),
            resultCodec: new ServiceReference(IssueCorrectionResultCodec::class, OperationResultCodec::class),
            outcomeProjector: new ServiceReference(IssueCorrectionOutcomeProjector::class, OutcomeProjector::class),
        );
    }
}
