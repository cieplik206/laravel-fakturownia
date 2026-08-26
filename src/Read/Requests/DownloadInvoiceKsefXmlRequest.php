<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\ArtifactFormat;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class DownloadInvoiceKsefXmlRequest extends StreamReadRequest
{
    public function __construct(string $invoiceId)
    {
        $invoiceId = RemoteIdentifier::assert($invoiceId);

        parent::__construct(
            ReadCapability::InvoiceKsefXmlStream->value,
            ReadCapability::InvoiceKsefXmlStream,
            "/invoices/{$invoiceId}/attachment",
            new QueryParameters(['kind' => 'gov']),
            ArtifactFormat::Xml,
            10_485_760,
        );
    }
}
