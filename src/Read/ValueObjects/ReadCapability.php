<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\ValueObjects;

enum ReadCapability: string
{
    case AccountInvoiceList = 'account.invoice.read';
    case InvoiceList = 'invoice.read.list';
    case InvoiceGet = 'invoice.read.get';
    case ClientList = 'client.read.list';
    case ClientGet = 'client.read.get';
    case ProductList = 'product.read.list';
    case ProductGet = 'product.read.get';
    case PaymentList = 'payment.read.list';
    case PaymentGet = 'payment.read.get';
    case InvoicePdfStream = 'invoice.pdf.stream';
    case InvoiceAttachmentsZipStream = 'invoice.attachments.zip.stream';
    case InvoiceKsefXmlStream = 'invoice.ksef.xml.stream';
    case InvoiceKsefUpoStream = 'invoice.ksef.upo.stream';
}
