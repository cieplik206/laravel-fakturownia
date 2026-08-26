<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\ArtifactFormat;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class DownloadInvoicePdfRequest extends StreamReadRequest
{
    public function __construct(string $invoiceId)
    {
        $invoiceId = RemoteIdentifier::assert($invoiceId);

        parent::__construct(
            ReadCapability::InvoicePdfStream->value,
            ReadCapability::InvoicePdfStream,
            "/invoices/{$invoiceId}.pdf",
            new QueryParameters,
            ArtifactFormat::Pdf,
            20_971_520,
        );
    }
}
