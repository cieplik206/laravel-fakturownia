<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactType;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfConfiguration;
use Cieplik206\Fakturownia\Stateful\Ksef\InvoiceKsefState;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefStatusCategory;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResource;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use InvalidArgumentException;

final readonly class DownloadInvoicePdfOperationFactory
{
    public function __construct(
        private HmacSha256 $hmac,
        private InvoicePdfConfiguration $configuration,
    ) {}

    public function make(
        InvoiceResource $resource,
        ?InvoiceKsefState $acceptedKsefState,
        IntegrationContext $context,
        string $renderingProfile = 'default',
        int $generation = 1,
        int $priority = 0,
    ): AcceptOperation {
        $this->assertKsefSource($resource, $acceptedKsefState);
        $revision = $this->hmac->digestCanonical(LookupHmacDomain::Payload, [
            'artifact_type' => ArtifactType::InvoicePdf->value,
            'connection_key' => $resource->connectionKey->value,
            'protocol' => 'cieplik206.fakturownia.invoice-pdf-revision.v1',
            'remote_id' => $resource->remoteId,
            'rendering_profile' => $renderingProfile,
            'resource_id' => $resource->id->value,
            'source_ksef_operation_id' => $acceptedKsefState?->lastOperationId->value,
            'source_gov_id' => $acceptedKsefState?->governmentId,
            'source_row_version' => $resource->rowVersion,
            'source_snapshot_hmac' => $resource->snapshotFingerprint->hex,
            'source_snapshot_hmac_key_version' => $resource->snapshotFingerprint->keyVersion,
        ]);
        $command = new DownloadInvoicePdfCommand(
            $resource->connectionKey,
            $resource->id,
            $resource->remoteId,
            $resource->snapshotFingerprint,
            $resource->rowVersion,
            $acceptedKsefState?->lastOperationId,
            $acceptedKsefState?->governmentId,
            $renderingProfile,
            $revision,
            $generation,
            $this->configuration->maximumBytes(),
        );

        return new AcceptOperation(
            scope: IntegrationScope::of('fakturownia', $resource->connectionKey->value),
            operationType: new OperationType(DownloadInvoicePdfOperationDefinitionProvider::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            intent: new IntentIdentity(
                resourceType: DownloadInvoicePdfOperationDefinitionProvider::ResourceType,
                semanticSlot: DownloadInvoicePdfOperationDefinitionProvider::SemanticSlot,
                localReference: new LocalReference(
                    DownloadInvoicePdfOperationDefinitionProvider::LocalReferenceType,
                    $revision->hex.':'.$generation,
                ),
            ),
            payload: (new DownloadInvoicePdfPayloadCodec)->encode($command),
            context: $context,
            priority: $priority,
        );
    }

    private function assertKsefSource(InvoiceResource $resource, ?InvoiceKsefState $state): void
    {
        if (! $state instanceof InvoiceKsefState) {
            return;
        }

        if (! $state->resourceId->equals($resource->id)
            || ! $state->connectionKey->equals($resource->connectionKey)
            || ! hash_equals($state->remoteId, $resource->remoteId)
            || $state->status->category() !== KsefStatusCategory::Succeeded
            || ! is_string($state->governmentId)
            || $state->acceptedAt === null) {
            throw new InvalidArgumentException('The invoice PDF KSeF source is not one accepted state for this resource.');
        }
    }
}
