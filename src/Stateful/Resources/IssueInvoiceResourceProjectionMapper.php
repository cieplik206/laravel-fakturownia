<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources;

use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceDraft;
use Cieplik206\Fakturownia\Stateful\Invoices\InvoiceLine;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoicePayloadCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use InvalidArgumentException;

final readonly class IssueInvoiceResourceProjectionMapper
{
    public const string OperationType = 'fakturownia.invoice.issue';

    private const string SnapshotFingerprintProtocol = 'cieplik206.fakturownia.invoice-resource.snapshot.v1';

    public function __construct(private HmacSha256 $hmac) {}

    public function map(OperationView $operation, OperationResult $result): InvoiceResourceProjectionPlan
    {
        if (! $result instanceof IssueInvoiceResult
            || $operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== self::OperationType) {
            throw new InvalidArgumentException('Issue invoice resource projection received an unsupported operation or result.');
        }

        $command = (new IssueInvoicePayloadCodec)->decode($operation->payload());

        if (! $command->identity->scope->connection->equals($operation->scope()->connection)) {
            throw new InvalidArgumentException('Issue invoice resource projection scope does not match its canonical payload.');
        }

        $localReference = $command->identity->transactionOrderReference();

        if ($localReference === null) {
            throw new InvalidArgumentException('Issue invoice resource projection requires a transaction-order reference.');
        }

        $this->assertResultMatchesDraft($command->draft, $command->identity->oid(), $result);
        $localLookup = InvoiceResourceLocalLookup::forTransactionOrder($this->hmac, $localReference);
        $encodedResult = (new IssueInvoiceResultCodec)->encode($result);
        $snapshotFingerprint = $this->hmac->digestCanonical(LookupHmacDomain::Payload, [
            'protocol' => self::SnapshotFingerprintProtocol,
            'result' => $encodedResult->toArray(),
        ]);

        return new InvoiceResourceProjectionPlan(
            resourceId: InvoiceResourceId::fromOperationId($operation->operationId()),
            connectionKey: $operation->scope()->connection,
            operationId: $operation->operationId(),
            localReferenceType: InvoiceResource::LocalReferenceType,
            localReferenceHmac: $localLookup->activeDigest,
            snapshot: $result,
            snapshotFingerprint: $snapshotFingerprint,
        );
    }

    private function assertResultMatchesDraft(
        InvoiceDraft $draft,
        ?string $expectedOid,
        IssueInvoiceResult $result,
    ): void {
        if (! hash_equals($draft->kind, $result->kind)
            || ! hash_equals($draft->issueDate, $result->issueDate)
            || $draft->number !== '' && ! hash_equals($draft->number, $result->number)
            || ! $draft->totalGross()->equals($result->totalGross)
            || ! $this->sameNullable($expectedOid, $result->oid)
            || ! $this->sameNullable(
                $draft->buyer->normalizedTaxIdentity(),
                $this->normalizeTaxIdentity($result->buyerTaxNumber),
            )
            || ! $this->samePositions($draft->positions, $result->positions)) {
            throw new InvalidArgumentException('Issue invoice result does not match the canonical operation payload.');
        }
    }

    /**
     * @param  list<InvoiceLine>  $expected
     * @param  list<InvoiceLine>  $actual
     */
    private function samePositions(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) {
            return false;
        }

        foreach ($expected as $index => $line) {
            $candidate = $actual[$index] ?? null;

            if (! $candidate instanceof InvoiceLine
                || ! hash_equals($line->name, $candidate->name)
                || ! hash_equals($line->tax, $candidate->tax)
                || ! hash_equals($line->quantity, $candidate->quantity)
                || ! hash_equals($line->unit, $candidate->unit)
                || ! $line->totalGross->equals($candidate->totalGross)) {
                return false;
            }
        }

        return true;
    }

    private function sameNullable(?string $expected, ?string $actual): bool
    {
        return $expected === null ? $actual === null : $actual !== null && hash_equals($expected, $actual);
    }

    private function normalizeTaxIdentity(?string $taxNumber): ?string
    {
        if ($taxNumber === null || trim($taxNumber) === '') {
            return null;
        }

        $normalized = preg_replace('/[\s.\-]+/u', '', strtoupper(trim($taxNumber)));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }
}
