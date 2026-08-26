<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

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

final class DownloadInvoicePdfOperationDefinitionProvider implements OperationDefinitionProvider
{
    public const string OperationType = 'fakturownia.invoice.pdf.download';

    public const string ResourceType = 'invoice_artifact';

    public const string SemanticSlot = 'pdf.download';

    public const string LocalReferenceType = 'invoice_artifact_revision';

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
                resourceType: self::ResourceType,
                localReferenceType: self::LocalReferenceType,
                semanticSlots: [self::SemanticSlot],
            ),
            handler: new ServiceReference(DownloadInvoicePdfOperationHandler::class, OperationHandler::class),
            failureClassifier: new ServiceReference(
                DownloadInvoicePdfFailureClassifier::class,
                FailureClassifier::class,
            ),
            retryPolicy: new ServiceReference(DownloadInvoicePdfRetryPolicy::class, RetryPolicy::class),
            reconciliationStrategy: new ServiceReference(
                DownloadInvoicePdfReconciliationStrategy::class,
                ReconciliationStrategy::class,
            ),
            resultCodec: new ServiceReference(InvoicePdfReadyResultCodec::class, OperationResultCodec::class),
            outcomeProjector: new ServiceReference(InvoicePdfOutcomeProjector::class, OutcomeProjector::class),
        );
    }
}
