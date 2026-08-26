<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Read\Data\ExactOidInvoiceQuery;
use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionFingerprint;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\Invoices\Identity\ExactOidLocator;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Throwable;

final readonly class AuthoritativeIssueCorrectionReconciliationStrategy implements AuthoritativeReconciliationStrategy
{
    public function __construct(
        private FakturowniaManager $manager,
        private HmacSha256 $hmac,
    ) {}

    public function reconcile(
        AuthoritativeReconciliationContext $context,
    ): AuthoritativeReconciliationOutcome {
        $command = (new IssueCorrectionPayloadCodec)->decode($context->payload());

        if (! $command->identity->scope->connection->equals($context->scope()->connection)) {
            return AuthoritativeReconciliationOutcome::inconclusive('fakturownia.correction.scope_mismatch');
        }

        $locator = $command->identity->exactLocator();

        if (! $locator instanceof ExactOidLocator) {
            return AuthoritativeReconciliationOutcome::ambiguousMatches(
                $this->manualReviewFailure(),
                'fakturownia.correction.exact_identity_unavailable',
            );
        }

        try {
            $matches = [];
            $expectedFingerprint = (new CorrectionFingerprint($this->hmac))->fromDraft($command->draft);
            $query = new ExactOidInvoiceQuery(
                $locator->oid,
                'correction',
                true,
                (string) $command->draft->issueDate,
            );

            foreach ($this->manager->connection($locator->scope->connection)->read()->invoices()->streamByExactOid($query) as $invoice) {
                if ($this->matchesIdentity($invoice, $command)
                    && (new CorrectionFingerprint($this->hmac))->fromRemote($invoice)->equals($expectedFingerprint)) {
                    $matches[] = $this->result($invoice, $command->draft->currency());
                }

                if (count($matches) > 1) {
                    break;
                }
            }
        } catch (Throwable) {
            return AuthoritativeReconciliationOutcome::inconclusive('fakturownia.correction.scan_incomplete');
        }

        return match (count($matches)) {
            0 => AuthoritativeReconciliationOutcome::inconclusive('fakturownia.correction.absence_not_conclusive'),
            1 => AuthoritativeReconciliationOutcome::foundExact($matches[0], 'fakturownia.correction.exact_match'),
            default => AuthoritativeReconciliationOutcome::ambiguousMatches(
                $this->manualReviewFailure(),
                'fakturownia.correction.multiple_exact_matches',
            ),
        };
    }

    private function matchesIdentity(InvoiceResponseData $invoice, IssueCorrectionCommand $command): bool
    {
        return $invoice->kind?->raw === 'correction'
            && $invoice->income === true
            && $invoice->departmentId === (string) $command->draft->departmentId
            && $invoice->sourceOid === $command->identity->oid()
            && $invoice->fromInvoiceId === $command->draft->sourceInvoiceId;
    }

    private function result(InvoiceResponseData $invoice, string $currency): IssueCorrectionResult
    {
        if ($invoice->number === null
            || $invoice->status === null
            || $invoice->priceGross === null
            || $invoice->currency !== $currency) {
            throw new \InvalidArgumentException('The exact correction candidate is incomplete.');
        }

        return new IssueCorrectionResult(
            $invoice->remoteId,
            (string) $invoice->fromInvoiceId,
            $invoice->number,
            $invoice->status->raw,
            Money::fromDecimal($invoice->priceGross->value, $currency),
        );
    }

    private function manualReviewFailure(): SafeOperationFailure
    {
        return new SafeOperationFailure(
            'fakturownia_correction_ambiguous',
            'Correction reconciliation requires manual review.',
        );
    }
}
