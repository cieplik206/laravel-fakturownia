<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

enum KsefDoctorIssue: string
{
    case GovAutoSendModeMismatch = 'gov_auto_send_mode_mismatch';
    case ValidateInvoicesForGovMismatch = 'validate_invoices_for_gov_mismatch';
    case BuyerCompanyMismatch = 'buyer_company_mismatch';
    case EvidenceExpired = 'evidence_expired';
    case EvidenceScopeMismatch = 'evidence_scope_mismatch';
    case EvidenceUnverified = 'evidence_unverified';
    case PilotProfileUnsupported = 'pilot_profile_unsupported';
}
