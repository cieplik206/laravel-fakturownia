<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

enum ArtifactType: string
{
    case InvoicePdf = 'invoice_pdf';
    case KsefXml = 'ksef_xml';
    case KsefUpo = 'ksef_upo';
    case Attachment = 'attachment';
}
