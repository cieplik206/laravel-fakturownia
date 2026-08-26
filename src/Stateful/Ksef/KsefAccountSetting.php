<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

enum KsefAccountSetting: string
{
    case GovAutoSendMode = 'gov_auto_send_mode';
    case ValidateInvoicesForGov = 'validate_invoices_for_gov';
    case BuyerCompany = 'buyer_company';
}
