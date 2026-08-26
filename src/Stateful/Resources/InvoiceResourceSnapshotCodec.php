<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources;

use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionResult;
use Cieplik206\Fakturownia\Stateful\Corrections\Operations\IssueCorrectionResultCodec;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResultCodec;
use Cieplik206\Fakturownia\Stateful\Resources\Contracts\InvoiceResourceSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use InvalidArgumentException;

final readonly class InvoiceResourceSnapshotCodec
{
    public function encode(InvoiceResourceSnapshot $snapshot): EncodedResult
    {
        return match (true) {
            $snapshot instanceof IssueInvoiceResult => (new IssueInvoiceResultCodec)->encode($snapshot),
            $snapshot instanceof IssueCorrectionResult => (new IssueCorrectionResultCodec)->encode($snapshot),
            default => throw new InvalidArgumentException('The invoice resource snapshot type is unsupported.'),
        };
    }

    public function decode(EncodedResult $result): InvoiceResourceSnapshot
    {
        $decoded = match ($result->resultType) {
            IssueInvoiceResultCodec::ResultType => (new IssueInvoiceResultCodec)->decode($result),
            IssueCorrectionResultCodec::ResultType => (new IssueCorrectionResultCodec)->decode($result),
            default => throw new InvalidArgumentException('The invoice resource snapshot result type is unsupported.'),
        };

        if (! $decoded instanceof InvoiceResourceSnapshot) {
            throw new InvalidArgumentException('The invoice resource snapshot codec returned an unsupported result.');
        }

        return $decoded;
    }
}
