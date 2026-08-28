<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Upload;

use Cieplik206\Fakturownia\Stateful\Attachments\Operations\AttachmentFailureClassifier;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\AttachmentRetryPolicy;
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

final class UploadAttachmentBinaryOperationDefinitionProvider implements OperationDefinitionProvider
{
    public static function provider(): ProviderKey
    {
        return new ProviderKey('fakturownia');
    }

    public static function definitions(): iterable
    {
        yield OperationDefinition::singleEffect(
            provider: self::provider(),
            operationType: new OperationType(UploadAttachmentBinaryOperationFactory::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'invoice',
                localReferenceType: 'invoice_attachment_upload',
                semanticSlots: [UploadAttachmentBinaryOperationFactory::SemanticSlot],
            ),
            handler: new ServiceReference(UploadAttachmentBinaryOperationHandler::class, OperationHandler::class),
            failureClassifier: new ServiceReference(AttachmentFailureClassifier::class, FailureClassifier::class),
            retryPolicy: new ServiceReference(AttachmentRetryPolicy::class, RetryPolicy::class),
            reconciliationStrategy: new ServiceReference(
                UploadAttachmentBinaryReconciliationStrategy::class,
                ReconciliationStrategy::class,
            ),
            resultCodec: new ServiceReference(AttachmentBinaryUploadedResultCodec::class, OperationResultCodec::class),
            outcomeProjector: new ServiceReference(UploadAttachmentBinaryOutcomeProjector::class, OutcomeProjector::class),
        );
    }
}
