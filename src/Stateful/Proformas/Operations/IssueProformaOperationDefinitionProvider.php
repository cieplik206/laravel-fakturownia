<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Proformas\Operations;

use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResultCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Proformas\Reconciliation\IssueProformaReconciliationStrategy;
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

final class IssueProformaOperationDefinitionProvider implements OperationDefinitionProvider
{
    public static function provider(): ProviderKey
    {
        return new ProviderKey('fakturownia');
    }

    public static function definitions(): iterable
    {
        yield OperationDefinition::singleEffect(
            provider: self::provider(),
            operationType: new OperationType(IssueProformaOperationFactory::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'invoice',
                localReferenceType: 'transaction_order',
                semanticSlots: [IssueProformaOperationFactory::SemanticSlot],
            ),
            handler: new ServiceReference(IssueProformaOperationHandler::class, OperationHandler::class),
            failureClassifier: new ServiceReference(IssueInvoiceFailureClassifier::class, FailureClassifier::class),
            retryPolicy: new ServiceReference(IssueInvoiceRetryPolicy::class, RetryPolicy::class),
            reconciliationStrategy: new ServiceReference(
                IssueProformaReconciliationStrategy::class,
                ReconciliationStrategy::class,
            ),
            resultCodec: new ServiceReference(IssueInvoiceResultCodec::class, OperationResultCodec::class),
            outcomeProjector: new ServiceReference(IssueProformaOutcomeProjector::class, OutcomeProjector::class),
        );
    }
}
