<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Enums\AmbiguousEffectAction;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\RetryMode;
use Cieplik206\IntegrationOperations\Registry\ManagedMutationIdentityContract;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\Registry\TerminalContract;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

final class DeleteCostInvoiceOperationDefinitionProvider implements OperationDefinitionProvider
{
    public static function provider(): ProviderKey
    {
        return new ProviderKey('fakturownia');
    }

    public static function definitions(): iterable
    {
        yield new OperationDefinition(
            provider: self::provider(),
            operationType: new OperationType(DeleteCostInvoiceOperationFactory::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            maximumRemoteWrites: 1,
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'invoice',
                localReferenceType: 'cost_invoice_delete',
                semanticSlots: [DeleteCostInvoiceOperationFactory::SemanticSlot],
            ),
            boundaryMode: BoundaryMode::Required,
            retryMode: RetryMode::EffectAware,
            safeRetryEvidence: ['request_not_started'],
            ambiguousEffectAction: AmbiguousEffectAction::Reconcile,
            reconciliationResults: ReconciliationResult::cases(),
            succeeded: new TerminalContract(
                OperationStatus::Succeeded,
                OperationDisposition::Succeeded,
                [EffectState::Applied],
                [ResultAvailability::Available],
            ),
            failed: new TerminalContract(
                OperationStatus::Failed,
                OperationDisposition::Failed,
                [EffectState::NotStarted, EffectState::NotApplied],
                [ResultAvailability::NotApplicable],
            ),
            cancelled: new TerminalContract(
                OperationStatus::Cancelled,
                OperationDisposition::Cancelled,
                [EffectState::NotStarted],
                [ResultAvailability::NotApplicable],
            ),
            handler: new ServiceReference(DeleteCostInvoiceOperationHandler::class, OperationHandler::class),
            failureClassifier: new ServiceReference(
                DeleteCostInvoiceFailureClassifier::class,
                FailureClassifier::class,
            ),
            retryPolicy: new ServiceReference(DeleteCostInvoiceRetryPolicy::class, RetryPolicy::class),
            reconciliationStrategy: new ServiceReference(
                DeleteCostInvoiceReconciliationStrategy::class,
                ReconciliationStrategy::class,
            ),
            resultCodec: new ServiceReference(DeleteCostInvoiceResultCodec::class, OperationResultCodec::class),
            outcomeProjector: new ServiceReference(DeleteCostInvoiceOutcomeProjector::class, OutcomeProjector::class),
        );
    }
}
