<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeOperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjector;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\PollingStrategy;
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
use Cieplik206\IntegrationOperations\Enums\WriteActivation;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinition;
use Cieplik206\IntegrationOperations\Registry\ManagedMutationIdentityContract;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\PollingContract;
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

final class AuthoritativeEnsureAcceptedOperationDefinitionProvider implements AuthoritativeOperationDefinitionProvider
{
    public static function provider(): ProviderKey
    {
        return EnsureAcceptedOperationDefinitionProvider::provider();
    }

    public static function definitions(): iterable
    {
        $maximumPlaintextBytes = EncodedResult::HardMaximumCanonicalBytes;
        $resultCodec = new ServiceReference(EnsureAcceptedResultCodec::class, OperationResultCodec::class);

        yield new AuthoritativeOperationDefinition(
            provider: self::provider(),
            operationType: new OperationType(EnsureAcceptedOperationDefinitionProvider::OperationType),
            versions: new OperationDefinitionVersions(1, 1, 1),
            maximumRemoteWrites: 1,
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'invoice',
                localReferenceType: EnsureAcceptedOperationDefinitionProvider::LocalReferenceType,
                semanticSlots: [EnsureAcceptedOperationDefinitionProvider::SemanticSlot],
            ),
            boundaryMode: BoundaryMode::Optional,
            initialLane: InitialOperationLane::Poll,
            successEffectPolicy: SuccessEffectPolicy::MayBeObservedExternally,
            writeActivation: new WriteActivationContract([
                EnsureAcceptedPayloadCodec::ExplicitWriteActivationSlot => WriteActivation::PollSendRequired,
                EnsureAcceptedPayloadCodec::ObserveOnlyWriteActivationSlot => WriteActivation::Disabled,
            ]),
            polling: new PollingContract(
                maximumAttempts: 2_000,
                deadlineSeconds: 86_400,
                minimumIntervalSeconds: 30,
                maximumIntervalSeconds: 900,
            ),
            retryMode: RetryMode::EffectAware,
            safeRetryEvidence: ['request_not_started'],
            ambiguousEffectAction: AmbiguousEffectAction::Reconcile,
            reconciliationResults: AuthoritativeReconciliationResult::cases(),
            terminalOutcomes: new TerminalOutcomeContract([
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator, TerminalProofKind::PreEffect],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Cancelled,
                    EffectState::NotStarted,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Operator],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Succeeded,
                    EffectState::NotStarted,
                    ResultAvailability::Available,
                    [TerminalProofKind::Poll],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotStarted,
                    ResultAvailability::Available,
                    [TerminalProofKind::Poll, TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::NotApplied,
                    ResultAvailability::NotApplicable,
                    [TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Succeeded,
                    EffectState::Applied,
                    ResultAvailability::Available,
                    [TerminalProofKind::Execute, TerminalProofKind::Poll, TerminalProofKind::Reconcile],
                ),
                new TerminalOutcomePair(
                    OperationStatus::Failed,
                    EffectState::Applied,
                    ResultAvailability::Available,
                    [TerminalProofKind::Poll, TerminalProofKind::Reconcile],
                ),
            ]),
            resultEnvelope: new ResultEnvelopeDescriptor(
                $resultCodec,
                EnsureAcceptedResultCodec::resultType(),
                EnsureAcceptedResultCodec::schemaVersion(),
                $maximumPlaintextBytes,
                ResultEnvelopeDescriptor::minimumAesGcmCiphertextBytes($maximumPlaintextBytes),
            ),
            transportTargets: [new TransportTargetDefinition(
                targetId: 'invoice.ksef.send',
                transport: 'https_json',
                method: 'GET',
                targetTemplate: '/invoices/{remote_id}',
            )],
            projection: new ProjectionContract(
                new ServiceReference(EnsureAcceptedOutcomeProjectionPlanner::class, OutcomeProjectionPlanner::class),
                EnsureAcceptedObservationProjectionPlanner::SchemaVersion,
                [
                    EnsureAcceptedObservationProjectionPlanner::StateTargetId,
                    EnsureAcceptedObservationProjectionPlanner::HistoryTargetId,
                ],
            ),
            observationProjection: new ProjectionContract(
                new ServiceReference(
                    EnsureAcceptedObservationProjectionPlanner::class,
                    ObservationProjectionPlanner::class,
                ),
                EnsureAcceptedObservationProjectionPlanner::SchemaVersion,
                [
                    EnsureAcceptedObservationProjectionPlanner::StateTargetId,
                    EnsureAcceptedObservationProjectionPlanner::HistoryTargetId,
                ],
            ),
            compensations: [],
            payloadCodec: new ServiceReference(EnsureAcceptedPayloadCodec::class, OperationPayloadCodec::class),
            handler: new ServiceReference(EnsureAcceptedOperationHandler::class, OperationHandler::class),
            failureClassifier: new ServiceReference(
                AuthoritativeEnsureAcceptedFailureClassifier::class,
                AuthoritativeFailureClassifier::class,
            ),
            retryPolicy: new ServiceReference(
                AuthoritativeEnsureAcceptedRetryPolicy::class,
                AuthoritativeRetryPolicy::class,
            ),
            reconciliationStrategy: new ServiceReference(
                AuthoritativeEnsureAcceptedReconciliationStrategy::class,
                AuthoritativeReconciliationStrategy::class,
            ),
            pollingStrategy: new ServiceReference(EnsureAcceptedPollingStrategy::class, PollingStrategy::class),
            observationProjector: new ServiceReference(
                EnsureAcceptedObservationProjector::class,
                ObservationProjector::class,
            ),
        );
    }
}
