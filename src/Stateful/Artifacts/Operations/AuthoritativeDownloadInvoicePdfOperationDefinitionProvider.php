<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactProjectionPlan;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\Enums\AmbiguousEffectAction;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\InitialOperationLane;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\RetryMode;
use Cieplik206\IntegrationOperations\Enums\SuccessEffectPolicy;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\ManagedMutationIdentityContract;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\ProjectionContract;
use Cieplik206\IntegrationOperations\Registry\ResultEnvelopeDescriptor;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomeContract;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomePair;
use Cieplik206\IntegrationOperations\Registry\TransportTargetDefinition;
use Cieplik206\IntegrationOperations\Registry\WriteActivationContract;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

final class AuthoritativeDownloadInvoicePdfOperationDefinitionProvider implements AuthoritativeOperationDefinitionProvider
{
    public static function provider(): ProviderKey
    {
        return DownloadInvoicePdfOperationDefinitionProvider::provider();
    }

    public static function definitions(): iterable
    {
        $maximumPlaintextBytes = EncodedResult::HardMaximumCanonicalBytes;
        $resultCodec = new ServiceReference(InvoicePdfReadyResultCodec::class, OperationResultCodec::class);

        yield new AuthoritativeOperationDefinition(
            provider: self::provider(),
            operationType: new OperationType(DownloadInvoicePdfOperationDefinitionProvider::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            maximumRemoteWrites: 1,
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: DownloadInvoicePdfOperationDefinitionProvider::ResourceType,
                localReferenceType: DownloadInvoicePdfOperationDefinitionProvider::LocalReferenceType,
                semanticSlots: [DownloadInvoicePdfOperationDefinitionProvider::SemanticSlot],
            ),
            boundaryMode: BoundaryMode::Required,
            initialLane: InitialOperationLane::Execute,
            successEffectPolicy: SuccessEffectPolicy::MustBeAppliedByOperation,
            writeActivation: WriteActivationContract::immediate(DownloadInvoicePdfPayloadCodec::WriteActivationSlot),
            polling: null,
            retryMode: RetryMode::EffectAware,
            safeRetryEvidence: ['request_not_started'],
            ambiguousEffectAction: AmbiguousEffectAction::Reconcile,
            reconciliationResults: AuthoritativeReconciliationResult::cases(),
            terminalOutcomes: new TerminalOutcomeContract([
                new TerminalOutcomePair(
                    OperationStatus::Cancelled,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator, TerminalProofKind::PreEffect],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotApplied,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::Applied,
                    ResultAvailability::Available,
                    [TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Succeeded,
                    EffectState::Applied,
                    ResultAvailability::Available,
                    [TerminalProofKind::Execute, TerminalProofKind::Reconcile],
                ),
            ]),
            resultEnvelope: new ResultEnvelopeDescriptor(
                $resultCodec,
                InvoicePdfReadyResultCodec::ResultType,
                InvoicePdfReadyResultCodec::SchemaVersion,
                $maximumPlaintextBytes,
                ResultEnvelopeDescriptor::minimumAesGcmCiphertextBytes($maximumPlaintextBytes),
            ),
            transportTargets: [
                new TransportTargetDefinition(
                    targetId: 'invoice.pdf.read',
                    transport: 'https_stream',
                    method: 'GET',
                    targetTemplate: '/invoices/{remote_id}.pdf',
                ),
                new TransportTargetDefinition(
                    targetId: 'invoice.pdf.artifact_put',
                    transport: 'content_addressed_store',
                    method: 'PUT',
                    targetTemplate: '/{content_address}',
                ),
            ],
            projection: new ProjectionContract(
                new ServiceReference(InvoicePdfOutcomeProjectionPlanner::class, OutcomeProjectionPlanner::class),
                ArtifactProjectionPlan::SchemaVersion,
                [ArtifactProjectionPlan::TargetId],
            ),
            observationProjection: null,
            compensations: [],
            payloadCodec: new ServiceReference(DownloadInvoicePdfPayloadCodec::class, OperationPayloadCodec::class),
            handler: new ServiceReference(DownloadInvoicePdfOperationHandler::class, OperationHandler::class),
            failureClassifier: new ServiceReference(
                AuthoritativeDownloadInvoicePdfFailureClassifier::class,
                AuthoritativeFailureClassifier::class,
            ),
            retryPolicy: new ServiceReference(
                AuthoritativeDownloadInvoicePdfRetryPolicy::class,
                AuthoritativeRetryPolicy::class,
            ),
            reconciliationStrategy: new ServiceReference(
                AuthoritativeDownloadInvoicePdfReconciliationStrategy::class,
                AuthoritativeReconciliationStrategy::class,
            ),
            pollingStrategy: null,
        );
    }
}
