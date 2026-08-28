<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerSession;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\SignedLiveProbeAuthorization;
use Cieplik206\Fakturownia\Tests\Contract\Support\InvoiceIdentityProbe;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoContractProbe;
use Cieplik206\Fakturownia\Tests\Contract\Support\ProbeConfiguration;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $session = NativeBrokerSession::fromStandardStreams();

    match ($session->authority->evidenceContract) {
        SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract => (new InvoiceIdentityProbe(
            ProbeConfiguration::fromNativeBrokerSession($session),
        ))->run(),
        SignedLiveProbeAuthorization::KsefDemoEvidenceContract => KsefDemoContractProbe::forNativeBrokerSession(
            $session,
        )->run(),
        default => throw new RuntimeException('The native broker selected an unsupported live evidence contract.'),
    };
} catch (Throwable $throwable) {
    fwrite(STDERR, "supervised live evidence probe denied: {$throwable->getMessage()}\n");

    exit(78);
}
