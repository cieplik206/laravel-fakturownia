<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionResult;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionResultCodec;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceLocalLookup;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceProjectionPlan;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use InvalidArgumentException;

final readonly class CorrectionResourceProjectionMapper
{
    private const string SnapshotFingerprintProtocol = 'cieplik206.fakturownia.correction-resource.snapshot.v1';

    public function __construct(private HmacSha256 $hmac) {}

    public function map(OperationView $operation, OperationResult $result): InvoiceResourceProjectionPlan
    {
        if (! $result instanceof IssueCorrectionResult
            || $operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== IssueCorrectionOperationDefinitionProvider::OperationType) {
            throw new InvalidArgumentException('Correction resource projection received an unsupported operation or result.');
        }

        $command = (new IssueCorrectionPayloadCodec)->decode($operation->payload());
        $localReference = $command->identity->transactionOrderReference();

        if (! $command->identity->scope->connection->equals($operation->scope()->connection)
            || $localReference === null
            || ! hash_equals($command->draft->sourceInvoiceId, $result->sourceInvoiceId)
            || ! $command->draft->totalGross()->equals($result->totalGross)) {
            throw new InvalidArgumentException('Correction resource projection does not match its canonical command.');
        }

        $localLookup = InvoiceResourceLocalLookup::forCustomerReturn($this->hmac, $localReference);
        $encodedResult = (new IssueCorrectionResultCodec)->encode($result);
        $snapshotFingerprint = $this->hmac->digestCanonical(LookupHmacDomain::Payload, [
            'protocol' => self::SnapshotFingerprintProtocol,
            'result' => $encodedResult->toArray(),
        ]);

        return new InvoiceResourceProjectionPlan(
            resourceId: InvoiceResourceId::fromOperationId($operation->operationId()),
            connectionKey: $operation->scope()->connection,
            operationId: $operation->operationId(),
            localReferenceType: $localLookup->referenceType,
            localReferenceHmac: $localLookup->activeDigest,
            snapshot: $result,
            snapshotFingerprint: $snapshotFingerprint,
        );
    }
}
