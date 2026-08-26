<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Shadow;

use Cieplik206\Fakturownia\Read\Data\InvoicePositionData;
use Cieplik206\Fakturownia\Read\Data\InvoiceResponseData;

final readonly class InvoiceShadowComparator
{
    public function compare(
        InvoiceResponseData $legacy,
        InvoiceResponseData $sdk,
    ): InvoiceShadowComparisonResult {
        $differences = [];
        $legacyFields = $this->invoiceFields($legacy);
        $sdkFields = $this->invoiceFields($sdk);

        foreach ($legacyFields as $path => $legacyValue) {
            if ($legacyValue === $sdkFields[$path]) {
                continue;
            }

            $differences[] = new ShadowDifference($path, ShadowDifferenceKind::ValueMismatch);
        }

        if (count($legacy->positions) !== count($sdk->positions)) {
            $differences[] = new ShadowDifference(
                'positions.count',
                ShadowDifferenceKind::PositionCountMismatch,
            );
        }

        if ($this->positionFingerprints($legacy->positions) !== $this->positionFingerprints($sdk->positions)) {
            $differences[] = new ShadowDifference(
                'positions.set',
                ShadowDifferenceKind::PositionSetMismatch,
            );
        }

        return new InvoiceShadowComparisonResult($differences);
    }

    /** @return array<string, bool|string|null> */
    private function invoiceFields(InvoiceResponseData $invoice): array
    {
        return [
            'remote_id' => $invoice->remoteId,
            'user_id' => $invoice->userId,
            'number' => $invoice->number,
            'kind' => $invoice->kind?->raw,
            'status' => $invoice->status?->raw,
            'issue_date' => $invoice->issueDate?->value,
            'sell_date' => $invoice->sellDate?->value,
            'payment_to' => $invoice->paymentTo?->value,
            'paid_date' => $invoice->paidDate?->value,
            'payment_type' => $invoice->paymentType,
            'price_net' => $invoice->priceNet?->value,
            'price_tax' => $invoice->priceTax?->value,
            'price_gross' => $invoice->priceGross?->value,
            'paid' => $invoice->paid?->value,
            'currency' => $invoice->currency,
            'description' => $invoice->description,
            'seller_name' => $invoice->sellerName,
            'seller_tax_number' => $invoice->sellerTaxNumber,
            'buyer_name' => $invoice->buyerName,
            'buyer_tax_number' => $invoice->buyerTaxNumber,
            'buyer_email' => $invoice->buyerEmail,
            'client_id' => $invoice->clientId,
            'department_id' => $invoice->departmentId,
            'source_oid' => $invoice->sourceOid,
            'from_invoice_id' => $invoice->fromInvoiceId,
            'income' => $invoice->income,
            'cancelled' => $invoice->cancelled,
            'government_id' => $invoice->governmentId,
            'government_status' => $invoice->governmentStatus,
            'created_at' => $invoice->createdAt?->value,
            'updated_at' => $invoice->updatedAt?->value,
        ];
    }

    /**
     * @param  list<InvoicePositionData>  $positions
     * @return list<string>
     */
    private function positionFingerprints(array $positions): array
    {
        $fingerprints = array_map(
            fn (InvoicePositionData $position): string => hash('sha256', $this->canonicalPosition($position)),
            $positions,
        );

        sort($fingerprints, SORT_STRING);

        return $fingerprints;
    }

    private function canonicalPosition(InvoicePositionData $position): string
    {
        $fields = [
            $position->remoteId,
            $position->invoiceId,
            $position->productId,
            $position->name,
            $position->code,
            $position->description,
            $position->quantity?->value,
            $position->unit,
            $position->tax,
            $position->priceNet?->value,
            $position->priceGross?->value,
            $position->totalPriceNet?->value,
            $position->totalPriceGross?->value,
        ];

        return json_encode($fields, JSON_THROW_ON_ERROR);
    }
}
