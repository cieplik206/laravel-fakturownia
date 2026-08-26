<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\ArtifactFormat;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class DownloadInvoiceKsefUpoRequest extends StreamReadRequest
{
    public function __construct(string $invoiceId)
    {
        $invoiceId = RemoteIdentifier::assert($invoiceId);

        parent::__construct(
            ReadCapability::InvoiceKsefUpoStream->value,
            ReadCapability::InvoiceKsefUpoStream,
            "/invoices/{$invoiceId}/attachment",
            new QueryParameters(['kind' => 'gov_upo']),
            ArtifactFormat::Upo,
            10_485_760,
        );
    }
}
