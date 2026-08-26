<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefInvoiceObservation;
use Cieplik206\Fakturownia\Stateful\Ksef\OpenKsefStatus;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefInvoiceObservationReader;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class FakturowniaKsefInvoiceObservationReader implements KsefInvoiceObservationReader
{
    public function __construct(private FakturowniaManager $manager) {}

    public function observe(ConnectionKey $connectionKey, string $remoteId): KsefInvoiceObservation
    {
        $invoice = $this->manager->connection($connectionKey)->read()->invoices()->get($remoteId);

        return new KsefInvoiceObservation(
            remoteId: $invoice->remoteId,
            status: new OpenKsefStatus($this->rawStatus($invoice)),
            governmentId: $invoice->governmentId,
            providerErrorCount: $this->providerErrorCount($invoice),
        );
    }

    private function rawStatus(InvoiceResponseData $invoice): string
    {
        if (is_string($invoice->governmentStatus) && $invoice->governmentStatus !== '') {
            return $invoice->governmentStatus;
        }

        return $invoice->governmentId === null ? 'not_sent' : 'unknown_missing_status';
    }

    private function providerErrorCount(InvoiceResponseData $invoice): int
    {
        $errors = $invoice->extra()['gov_error_messages'] ?? null;

        if ($errors === null || $errors === '' || $errors === []) {
            return 0;
        }

        return is_array($errors) ? min(count($errors), 10_000) : 1;
    }
}
