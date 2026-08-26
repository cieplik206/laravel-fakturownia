<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\ArtifactFormat;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class DownloadInvoiceAttachmentsZipRequest extends StreamReadRequest
{
    public function __construct(string $invoiceId)
    {
        $invoiceId = RemoteIdentifier::assert($invoiceId);

        parent::__construct(
            ReadCapability::InvoiceAttachmentsZipStream->value,
            ReadCapability::InvoiceAttachmentsZipStream,
            "/invoices/{$invoiceId}/attachments_zip.json",
            new QueryParameters,
            ArtifactFormat::Zip,
            52_428_800,
        );
    }
}
