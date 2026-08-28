<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectDisposition;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionProposal;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionReceipt;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionResponse;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionResult;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredEffectExecutionResultVerifier;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredReadObservationDisposition;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredReadObservationProposal;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredReadObservationResponse;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredReadObservationResult;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\BrokeredReadObservationResultVerifier;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\CanonicalCodec;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConcurrentBrokeredEffectExecutionProposal;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\ConsumptionClaimRequest;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\LiveEffectDescriptor;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerAuthorityHandoff;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerProbePlan;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerSession;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerTrustPolicy;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeBrokerWireFrame;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeSupervisorAttestation;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\NativeSupervisorAttestationVerifier;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\SignedLiveProbeAuthorization;
use Cieplik206\Fakturownia\Tests\Contract\Support\CreateKsefDemoInvoiceRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\CreateProbeInvoiceRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\FakturowniaProbeConnector;
use Cieplik206\Fakturownia\Tests\Contract\Support\InvoiceIdentityProbe;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoConnector;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoContractProbe;
use Cieplik206\Fakturownia\Tests\Contract\Support\KsefDemoProbeConfiguration;
use Cieplik206\Fakturownia\Tests\Contract\Support\NativeBrokerSaloonSender;
use Cieplik206\Fakturownia\Tests\Contract\Support\ProbeConfiguration;
use Cieplik206\Fakturownia\Tests\Contract\Support\SearchProbeInvoicesRequest;
use Cieplik206\Fakturownia\Tests\Contract\Support\SendKsefDemoInvoiceRequest;

class_exists(ProbeConfiguration::class);
class_exists(InvoiceIdentityProbe::class);
class_exists(KsefDemoProbeConfiguration::class);
class_exists(KsefDemoContractProbe::class);
class_exists(NativeBrokerSaloonSender::class);

/**
 * @return array{
 *     document: array<string, mixed>,
 *     public_key: non-empty-string,
 *     policy_secret_key: non-empty-string,
 *     supervisor_secret_key: non-empty-string,
 *     supervisor_public_key: non-empty-string,
 *     effect_result_secret_key: non-empty-string,
 *     effect_result_public_key: non-empty-string
 * }
 */
function fakturowniaNativeBrokerTrustPolicyFixture(): array
{
    $policyKeyPair = sodium_crypto_sign_keypair();
    $supervisorKeyPair = sodium_crypto_sign_keypair();
    $effectResultKeyPair = sodium_crypto_sign_keypair();
    $policySecretKey = sodium_crypto_sign_secretkey($policyKeyPair);
    $policyPublicKey = sodium_crypto_sign_publickey($policyKeyPair);
    $supervisorSecretKey = sodium_crypto_sign_secretkey($supervisorKeyPair);
    $supervisorPublicKey = sodium_crypto_sign_publickey($supervisorKeyPair);
    $effectResultSecretKey = sodium_crypto_sign_secretkey($effectResultKeyPair);
    $effectResultPublicKey = sodium_crypto_sign_publickey($effectResultKeyPair);

    $envelope = [
        'contract' => NativeBrokerTrustPolicy::Contract,
        'version' => NativeBrokerTrustPolicy::Version,
        'algorithm' => NativeBrokerTrustPolicy::Algorithm,
        'signer_id' => 'deployment-policy-1',
        'issued_at' => '2026-08-27T07:50:00.000000Z',
        'expires_at' => '2026-08-27T09:00:00.000000Z',
        'broker_policy_sha256' => str_repeat('3', 64),
        'supervisor_semantics_sha256' => str_repeat('4', 64),
        'argv_sha256' => str_repeat('5', 64),
        'environment_sha256' => str_repeat('6', 64),
        'probe_uid' => 991,
        'probe_gid' => 991,
        'supervisor_signer' => [
            'id' => 'native-supervisor-1',
            'algorithm' => NativeBrokerTrustPolicy::Algorithm,
            'public_key' => base64_encode($supervisorPublicKey),
        ],
        'effect_result_signer' => [
            'id' => 'native-effect-result-1',
            'algorithm' => NativeBrokerTrustPolicy::Algorithm,
            'public_key' => base64_encode($effectResultPublicKey),
        ],
    ];

    return [
        'document' => fakturowniaSignNativeBrokerDocument($envelope, $policySecretKey),
        'public_key' => base64_encode($policyPublicKey),
        'policy_secret_key' => $policySecretKey,
        'supervisor_secret_key' => $supervisorSecretKey,
        'supervisor_public_key' => $supervisorPublicKey,
        'effect_result_secret_key' => $effectResultSecretKey,
        'effect_result_public_key' => $effectResultPublicKey,
    ];
}

/**
 * @param  array<string, mixed>  $envelope
 * @param  non-empty-string  $secretKey
 * @return array{envelope: array<string, mixed>, signature: string}
 */
function fakturowniaSignNativeBrokerDocument(array $envelope, string $secretKey): array
{
    return [
        'envelope' => $envelope,
        'signature' => base64_encode(sodium_crypto_sign_detached(CanonicalCodec::encode($envelope), $secretKey)),
    ];
}

/**
 * @param  non-empty-string|null  $secretKey
 * @return array{document: array<string, mixed>, public_key: non-empty-string}
 */
function fakturowniaNativeSupervisorAttestationFixture(
    ?string $secretKey = null,
    string $signerId = 'native-supervisor-1',
    string $authorizationSetSha256 = '',
    string $authorizationBundleSha256 = '',
    string $probePlanSha256 = '',
): array {
    $keyPair = $secretKey === null ? sodium_crypto_sign_keypair() : null;
    $secretKey ??= sodium_crypto_sign_secretkey($keyPair);
    $publicKey = $keyPair === null
        ? sodium_crypto_sign_publickey_from_secretkey($secretKey)
        : sodium_crypto_sign_publickey($keyPair);

    $envelope = [
        'contract' => NativeSupervisorAttestation::Contract,
        'version' => NativeSupervisorAttestation::Version,
        'algorithm' => NativeSupervisorAttestation::Algorithm,
        'signer_id' => $signerId,
        'issued_at' => '2026-08-27T08:00:00.000000Z',
        'expires_at' => '2026-08-27T08:10:00.000000Z',
        'launch_manifest_sha256' => str_repeat('1', 64),
        'run_nonce' => base64_encode(str_repeat('n', 32)),
        'authorization_set_sha256' => $authorizationSetSha256 !== ''
            ? $authorizationSetSha256
            : str_repeat('2', 64),
        'authorization_bundle_sha256' => $authorizationBundleSha256 !== ''
            ? $authorizationBundleSha256
            : str_repeat('a', 64),
        'probe_plan_sha256' => $probePlanSha256 !== ''
            ? $probePlanSha256
            : str_repeat('b', 64),
        'broker_policy_sha256' => str_repeat('3', 64),
        'supervisor_semantics_sha256' => str_repeat('4', 64),
        'argv_sha256' => str_repeat('5', 64),
        'environment_sha256' => str_repeat('6', 64),
        'probe_uid' => 991,
        'probe_gid' => 991,
    ];

    return [
        'document' => fakturowniaSignNativeBrokerDocument($envelope, $secretKey),
        'public_key' => $publicKey,
    ];
}

/**
 * @return array{
 *     document: array<string, mixed>,
 *     authorization_set_sha256: string,
 *     authorization_bundle_sha256: string,
 *     probe_plan_sha256: string,
 *     claim_request_sha256: string,
 *     consumption_receipt_sha256: string
 * }
 */
function fakturowniaNativeAuthorizationBundleFixture(): array
{
    $launchManifestSha256 = str_repeat('1', 64);
    $runId = str_repeat('a', 32);
    $claimNonce = base64_encode(str_repeat('q', 32));
    $limits = [
        'visibility_window_ms' => 30_000,
        'poll_interval_ms' => 250,
        'max_search_pages' => 20,
        'lost_response_timeout_ms' => 2_000,
        'connect_timeout_ms' => 2_000,
        'request_timeout_ms' => 15_000,
        'write_attempt_budget' => 11,
    ];
    $plan = [
        'contract' => NativeBrokerProbePlan::Contract,
        'version' => NativeBrokerProbePlan::Version,
        'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
        'environment' => 'demo_pl',
        'limits' => $limits,
        'targets' => [
            [
                'target_key' => 'primary',
                'expected_account_fingerprint' => str_repeat('5', 64),
            ],
            [
                'target_key' => 'secondary',
                'expected_account_fingerprint' => str_repeat('6', 64),
            ],
        ],
        'payload' => [
            'invoice' => ['kind' => 'vat'],
            'secondary_account_invoice' => ['kind' => 'vat'],
            'correction_invoice' => ['kind' => 'correction'],
            'secondary_department_id' => 'secondary-department',
            'safety' => [
                'throwaway_tenants' => true,
                'ksef_auto_send_disabled' => true,
                'email_delivery_disabled' => true,
            ],
        ],
    ];
    $harness = [
        'repository_commit' => str_repeat('c', 40),
        'code_sha256' => str_repeat('d', 64),
        'launch_manifest_sha256' => $launchManifestSha256,
    ];
    $authorizationEnvelope = [
        'contract' => SignedLiveProbeAuthorization::Contract,
        'version' => SignedLiveProbeAuthorization::Version,
        'algorithm' => SignedLiveProbeAuthorization::Algorithm,
        'signer_id' => 'root-operator',
        'issued_at' => '2026-08-27T07:50:00.000000Z',
        'expires_at' => '2026-08-27T09:00:00.000000Z',
        'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
        'challenge' => base64_encode(str_repeat('h', 32)),
        'harness' => $harness,
        'target' => [
            'environment' => 'demo_pl',
            'profile' => 'invoice_identity',
            'tenant_hmac_sha256' => str_repeat('e', 64),
            'account_hmac_sha256' => str_repeat('f', 64),
        ],
        'commitments' => [
            'scheme' => SignedLiveProbeAuthorization::CommitmentScheme,
            'configuration_hmac_sha256' => str_repeat('1', 64),
            'policy_hmac_sha256' => str_repeat('2', 64),
            'safety_hmac_sha256' => str_repeat('3', 64),
            'templates_hmac_sha256' => str_repeat('4', 64),
        ],
        'consumption' => [
            'authority_id' => 's03-external-cas',
            'authority_policy_sha256' => str_repeat('7', 64),
            'store_id' => 's03-ledger',
            'store_identity_sha256' => str_repeat('8', 64),
            'run_id' => $runId,
            'replay_policy' => ConsumptionClaimRequest::ReplayPolicy,
        ],
        'limits' => $limits,
    ];
    $authorization = [
        'envelope' => $authorizationEnvelope,
        'signature' => base64_encode(str_repeat('s', 64)),
    ];
    $setSha256 = static fn (array $rows): string => hash('sha256', CanonicalCodec::encode([
        'contract' => 'cieplik206.fakturownia.authorization-consumption-set',
        'version' => '1',
        'value' => $rows,
    ]));
    $authorizationSetSha256 = $setSha256([[
        'profile' => 'invoice_identity',
        'sha256' => hash('sha256', CanonicalCodec::encode($authorization)),
    ]]);
    $challengeSetSha256 = $setSha256([[
        'profile' => 'invoice_identity',
        'sha256' => hash('sha256', $authorizationEnvelope['challenge']),
    ]]);
    $configurationSetSha256 = $setSha256([[
        'profile' => 'invoice_identity',
        'sha256' => $authorizationEnvelope['commitments']['configuration_hmac_sha256'],
    ]]);
    $claimRequest = [
        'contract' => ConsumptionClaimRequest::Contract,
        'version' => ConsumptionClaimRequest::Version,
        'authority_id' => 's03-external-cas',
        'authority_policy_sha256' => str_repeat('7', 64),
        'store_id' => 's03-ledger',
        'store_identity_sha256' => str_repeat('8', 64),
        'run_id' => $runId,
        'run_started_at' => '2026-08-27T07:59:59.000000Z',
        'claim_nonce' => $claimNonce,
        'harness' => $harness,
        'authorization_set_sha256' => $authorizationSetSha256,
        'challenge_set_sha256' => $challengeSetSha256,
        'configuration_set_sha256' => $configurationSetSha256,
        'replay_policy' => ConsumptionClaimRequest::ReplayPolicy,
    ];
    $claimRequestSha256 = hash('sha256', CanonicalCodec::encode($claimRequest));
    $receipt = [
        'envelope' => [
            'contract' => 'cieplik206.fakturownia.authorization-consumption-receipt',
            'version' => '1',
            'algorithm' => 'Ed25519',
            'signer_id' => 's03-external-cas',
            'issued_at' => '2026-08-27T07:59:59.500000Z',
            'expires_at' => '2026-08-27T08:00:30.000000Z',
            'claim_cursor' => ['store_id' => 's03-ledger', 'sequence' => '1'],
            'disposition' => 'fresh_direct_grant',
            'claim_request' => $claimRequest,
            'claim_request_sha256' => $claimRequestSha256,
        ],
        'signature' => base64_encode(str_repeat('r', 64)),
    ];
    $consumptionReceiptSha256 = hash('sha256', CanonicalCodec::encode($receipt));
    $bundle = [
        'contract' => 'cieplik206.fakturownia.native-authorization-bundle',
        'version' => '1',
        'run_id' => $runId,
        'run_started_at' => '2026-08-27T07:59:59.000000Z',
        'claim_nonce' => $claimNonce,
        'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
        'launch_manifest_sha256' => $launchManifestSha256,
        'authorization_set_sha256' => $authorizationSetSha256,
        'challenge_set_sha256' => $challengeSetSha256,
        'configuration_set_sha256' => $configurationSetSha256,
        'claim_request_sha256' => $claimRequestSha256,
        'consumption_receipt_sha256' => $consumptionReceiptSha256,
        'probe_plan' => $plan,
        'authorizations' => [$authorization],
        'consumption_receipt' => $receipt,
    ];

    return [
        'document' => $bundle,
        'authorization_set_sha256' => $authorizationSetSha256,
        'authorization_bundle_sha256' => hash('sha256', CanonicalCodec::encode($bundle)),
        'probe_plan_sha256' => hash('sha256', CanonicalCodec::encode($plan)),
        'claim_request_sha256' => $claimRequestSha256,
        'consumption_receipt_sha256' => $consumptionReceiptSha256,
    ];
}

/**
 * @return array{
 *     document: array<string, mixed>,
 *     authorization_set_sha256: string,
 *     authorization_bundle_sha256: string,
 *     probe_plan_sha256: string,
 *     claim_request_sha256: string,
 *     consumption_receipt_sha256: string
 * }
 */
function fakturowniaNativeKsefAuthorizationBundleFixture(): array
{
    $launchManifestSha256 = str_repeat('1', 64);
    $runId = str_repeat('a', 32);
    $claimNonce = base64_encode(str_repeat('q', 32));
    $profiles = ['explicit_block', 'explicit_persist', 'auto_block', 'auto_persist'];
    $limits = [
        'poll_window_ms' => 30_000,
        'poll_interval_ms' => 500,
        'max_search_pages' => 10,
        'pre_send_observation_window_ms' => 1_000,
        'visibility_window_ms' => 10_000,
        'visibility_poll_interval_ms' => 250,
        'connect_timeout_ms' => 5_000,
        'request_timeout_ms' => 30_000,
        'minimum_pdf_size_bytes' => 1_024,
    ];
    $validInvoice = [
        'department_id' => '123',
        'issue_date' => '2026-08-27',
        'sell_date' => '2026-08-27',
        'payment_to_kind' => 'off',
        'buyer_name' => 'DEMO Buyer',
        'buyer_tax_no' => '1234567890',
        'buyer_company' => true,
        'buyer_country' => 'PL',
        'currency' => 'PLN',
        'positions' => [[
            'name' => 'DEMO item',
            'quantity' => 1,
            'price_net' => 10,
            'tax' => 23,
        ]],
    ];
    $invalidInvoice = [...$validInvoice, 'buyer_tax_no' => ''];
    $targets = [];
    $authorizations = [];
    $harness = [
        'repository_commit' => str_repeat('c', 40),
        'code_sha256' => str_repeat('d', 64),
        'launch_manifest_sha256' => $launchManifestSha256,
    ];

    foreach ($profiles as $index => $profile) {
        $autoSend = str_starts_with($profile, 'auto_');
        $blockInvalid = str_ends_with($profile, '_block');
        $settingsChecksum = hash('sha256', "settings-{$profile}");
        $targets[] = [
            'profile' => $profile,
            'target_key' => $profile,
            'expected_account_fingerprint' => hash('sha256', "account-{$profile}"),
            'ownership' => $autoSend ? 'provider_auto_send' : 'explicit_sdk',
            'validation_mode' => $blockInvalid ? 'block_invalid' : 'persist_with_errors',
            'expected_validation_field' => 'buyer_tax_no',
            'ksef_environment' => 'demo',
            'gov_auto_send_mode' => $autoSend ? 'pl_companies' : null,
            'validate_invoices_for_gov' => $blockInvalid,
            'buyer_company' => true,
            'throwaway_tenant' => true,
            'email_delivery_disabled' => true,
            'payments_disabled' => true,
            'webhooks_disabled' => true,
            'settings_checksum' => $settingsChecksum,
            'valid_invoice' => $validInvoice,
            'invalid_invoice' => $invalidInvoice,
        ];
        $authorizationEnvelope = [
            'contract' => SignedLiveProbeAuthorization::Contract,
            'version' => SignedLiveProbeAuthorization::Version,
            'algorithm' => SignedLiveProbeAuthorization::Algorithm,
            'signer_id' => "root-operator-{$index}",
            'issued_at' => '2026-08-27T07:50:00.000000Z',
            'expires_at' => '2026-08-27T09:00:00.000000Z',
            'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
            'challenge' => base64_encode(str_repeat(chr(65 + $index), 32)),
            'harness' => $harness,
            'target' => [
                'environment' => 'ksef_demo',
                'profile' => $profile,
                'tenant_hmac_sha256' => hash('sha256', "tenant-{$profile}"),
                'account_hmac_sha256' => hash('sha256', "account-hmac-{$profile}"),
            ],
            'commitments' => [
                'scheme' => SignedLiveProbeAuthorization::CommitmentScheme,
                'configuration_hmac_sha256' => hash('sha256', "configuration-{$profile}"),
                'policy_hmac_sha256' => hash('sha256', "policy-{$profile}"),
                'safety_hmac_sha256' => hash('sha256', "safety-{$profile}"),
                'templates_hmac_sha256' => hash('sha256', "templates-{$profile}"),
            ],
            'consumption' => [
                'authority_id' => 's04-external-cas',
                'authority_policy_sha256' => str_repeat('7', 64),
                'store_id' => 's04-ledger',
                'store_identity_sha256' => str_repeat('8', 64),
                'run_id' => $runId,
                'replay_policy' => ConsumptionClaimRequest::ReplayPolicy,
            ],
            'limits' => $limits,
        ];
        $authorizations[] = [
            'envelope' => $authorizationEnvelope,
            'signature' => base64_encode(str_repeat(chr(97 + $index), 64)),
        ];
    }

    $plan = [
        'contract' => NativeBrokerProbePlan::Contract,
        'version' => NativeBrokerProbePlan::Version,
        'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
        'environment' => 'ksef_demo',
        'limits' => $limits,
        'targets' => $targets,
    ];
    $setSha256 = static function (array $rows): string {
        usort($rows, static fn (array $left, array $right): int => $left['profile'] <=> $right['profile']);

        return hash('sha256', CanonicalCodec::encode([
            'contract' => 'cieplik206.fakturownia.authorization-consumption-set',
            'version' => '1',
            'value' => $rows,
        ]));
    };
    $authorizationSetSha256 = $setSha256(array_map(
        static fn (array $authorization): array => [
            'profile' => $authorization['envelope']['target']['profile'],
            'sha256' => hash('sha256', CanonicalCodec::encode($authorization)),
        ],
        $authorizations,
    ));
    $challengeSetSha256 = $setSha256(array_map(
        static fn (array $authorization): array => [
            'profile' => $authorization['envelope']['target']['profile'],
            'sha256' => hash('sha256', $authorization['envelope']['challenge']),
        ],
        $authorizations,
    ));
    $configurationSetSha256 = $setSha256(array_map(
        static fn (array $authorization): array => [
            'profile' => $authorization['envelope']['target']['profile'],
            'sha256' => $authorization['envelope']['commitments']['configuration_hmac_sha256'],
        ],
        $authorizations,
    ));
    $claimRequest = [
        'contract' => ConsumptionClaimRequest::Contract,
        'version' => ConsumptionClaimRequest::Version,
        'authority_id' => 's04-external-cas',
        'authority_policy_sha256' => str_repeat('7', 64),
        'store_id' => 's04-ledger',
        'store_identity_sha256' => str_repeat('8', 64),
        'run_id' => $runId,
        'run_started_at' => '2026-08-27T07:59:59.000000Z',
        'claim_nonce' => $claimNonce,
        'harness' => $harness,
        'authorization_set_sha256' => $authorizationSetSha256,
        'challenge_set_sha256' => $challengeSetSha256,
        'configuration_set_sha256' => $configurationSetSha256,
        'replay_policy' => ConsumptionClaimRequest::ReplayPolicy,
    ];
    $claimRequestSha256 = hash('sha256', CanonicalCodec::encode($claimRequest));
    $receipt = [
        'envelope' => [
            'contract' => 'cieplik206.fakturownia.authorization-consumption-receipt',
            'version' => '1',
            'algorithm' => 'Ed25519',
            'signer_id' => 's04-external-cas',
            'issued_at' => '2026-08-27T07:59:59.500000Z',
            'expires_at' => '2026-08-27T08:00:30.000000Z',
            'claim_cursor' => ['store_id' => 's04-ledger', 'sequence' => '1'],
            'disposition' => 'fresh_direct_grant',
            'claim_request' => $claimRequest,
            'claim_request_sha256' => $claimRequestSha256,
        ],
        'signature' => base64_encode(str_repeat('r', 64)),
    ];
    $consumptionReceiptSha256 = hash('sha256', CanonicalCodec::encode($receipt));
    $bundle = [
        'contract' => 'cieplik206.fakturownia.native-authorization-bundle',
        'version' => '1',
        'run_id' => $runId,
        'run_started_at' => '2026-08-27T07:59:59.000000Z',
        'claim_nonce' => $claimNonce,
        'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
        'launch_manifest_sha256' => $launchManifestSha256,
        'authorization_set_sha256' => $authorizationSetSha256,
        'challenge_set_sha256' => $challengeSetSha256,
        'configuration_set_sha256' => $configurationSetSha256,
        'claim_request_sha256' => $claimRequestSha256,
        'consumption_receipt_sha256' => $consumptionReceiptSha256,
        'probe_plan' => $plan,
        'authorizations' => $authorizations,
        'consumption_receipt' => $receipt,
    ];

    return [
        'document' => $bundle,
        'authorization_set_sha256' => $authorizationSetSha256,
        'authorization_bundle_sha256' => hash('sha256', CanonicalCodec::encode($bundle)),
        'probe_plan_sha256' => hash('sha256', CanonicalCodec::encode($plan)),
        'claim_request_sha256' => $claimRequestSha256,
        'consumption_receipt_sha256' => $consumptionReceiptSha256,
    ];
}

/**
 * @param  non-empty-string|null  $secretKey
 * @return array{document: array<string, mixed>, public_key: non-empty-string}
 */
function fakturowniaBrokeredEffectResultFixture(
    BrokeredEffectDisposition $disposition = BrokeredEffectDisposition::Applied,
    ?string $secretKey = null,
    string $signerId = 'native-effect-result-1',
    ?string $supervisorAttestationSha256 = null,
    ?string $effectDescriptorSha256 = null,
    ?string $authorizationSetSha256 = null,
): array {
    $keyPair = $secretKey === null ? sodium_crypto_sign_keypair() : null;
    $secretKey ??= sodium_crypto_sign_secretkey($keyPair);
    $publicKey = $keyPair === null
        ? sodium_crypto_sign_publickey_from_secretkey($secretKey)
        : sodium_crypto_sign_publickey($keyPair);

    $response = $disposition === BrokeredEffectDisposition::Applied
        ? '{"id":123,"number":"FV/1/2026"}'
        : '';
    $requestStartedAt = in_array($disposition, [
        BrokeredEffectDisposition::Applied,
        BrokeredEffectDisposition::PossiblyApplied,
    ], true) ? '2026-08-27T08:00:01.000000Z' : null;
    $envelope = [
        'contract' => BrokeredEffectExecutionResult::Contract,
        'version' => BrokeredEffectExecutionResult::Version,
        'algorithm' => BrokeredEffectExecutionResult::Algorithm,
        'signer_id' => $signerId,
        'issued_at' => '2026-08-27T08:00:02.000000Z',
        'expires_at' => '2026-08-27T08:09:59.000000Z',
        'launch_manifest_sha256' => str_repeat('1', 64),
        'run_nonce' => base64_encode(str_repeat('n', 32)),
        'authorization_set_sha256' => $authorizationSetSha256 ?? str_repeat('2', 64),
        'broker_policy_sha256' => str_repeat('3', 64),
        'supervisor_attestation_sha256' => $supervisorAttestationSha256 ?? str_repeat('4', 64),
        'effect_descriptor_sha256' => $effectDescriptorSha256 ?? str_repeat('5', 64),
        'effect_id' => str_repeat('6', 32),
        'cas_record_sha256' => str_repeat('7', 64),
        'disposition' => $disposition->value,
        'request_started_at' => $requestStartedAt,
        'response_received_at' => $disposition === BrokeredEffectDisposition::Applied
            ? '2026-08-27T08:00:01.250000Z'
            : null,
        'http_status' => $disposition === BrokeredEffectDisposition::Applied ? 201 : 0,
        'content_type' => $disposition === BrokeredEffectDisposition::Applied ? 'application/json' : null,
        'provider_request_id_hmac_sha256' => $disposition === BrokeredEffectDisposition::Applied
            ? str_repeat('8', 64)
            : null,
        'response_body_base64' => base64_encode($response),
        'response_body_sha256' => hash('sha256', $response),
        'response_size_bytes' => strlen($response),
    ];

    return [
        'document' => fakturowniaSignNativeBrokerDocument($envelope, $secretKey),
        'public_key' => $publicKey,
    ];
}

/**
 * @param  non-empty-string  $secretKey
 * @return array{document: array<string, mixed>}
 */
function fakturowniaBrokeredEffectReceiptFixture(
    LiveEffectDescriptor $descriptor,
    BrokeredEffectExecutionResult $result,
    string $secretKey,
): array {
    $envelope = [
        'contract' => BrokeredEffectExecutionReceipt::Contract,
        'version' => BrokeredEffectExecutionReceipt::Version,
        'algorithm' => BrokeredEffectExecutionReceipt::Algorithm,
        'signer_id' => $result->signerId,
        'issued_at' => $result->issuedAt,
        'expires_at' => $result->expiresAt,
        'descriptor' => $descriptor->toArray(),
        'cas_record_sha256' => $result->casRecordSha256,
        'disposition' => $result->disposition->value,
        'request_started_at' => $result->requestStartedAt,
        'response_received_at' => $result->responseReceivedAt,
        'http_status' => $result->httpStatus,
        'content_type' => $result->contentType,
        'provider_request_id_hmac_sha256' => $result->providerRequestIdHmacSha256,
        'response_body_sha256' => $result->responseBodySha256,
        'response_size_bytes' => $result->responseSizeBytes,
    ];

    return ['document' => fakturowniaSignNativeBrokerDocument($envelope, $secretKey)];
}

/**
 * @param  non-empty-string  $secretKey
 * @return array{document: array<string, mixed>, public_key: non-empty-string}
 */
function fakturowniaBrokeredReadResultFixture(
    BrokeredReadObservationProposal $proposal,
    NativeSupervisorAttestation $attestation,
    string $secretKey,
    string $response = '{"id":123}',
): array {
    $envelope = [
        'contract' => BrokeredReadObservationResult::Contract,
        'version' => BrokeredReadObservationResult::Version,
        'algorithm' => BrokeredReadObservationResult::Algorithm,
        'signer_id' => 'native-effect-result-1',
        'issued_at' => '2026-08-27T08:00:02.000000Z',
        'expires_at' => '2026-08-27T08:09:59.000000Z',
        'launch_manifest_sha256' => $attestation->launchManifestSha256,
        'run_nonce' => $attestation->runNonce,
        'authorization_set_sha256' => $attestation->authorizationSetSha256,
        'authorization_bundle_sha256' => $attestation->authorizationBundleSha256,
        'probe_plan_sha256' => $attestation->probePlanSha256,
        'broker_policy_sha256' => $attestation->brokerPolicySha256,
        'supervisor_attestation_sha256' => $attestation->sha256(),
        'proposal_sha256' => $proposal->sha256(),
        'observation_id' => $proposal->observationId,
        'disposition' => BrokeredReadObservationDisposition::Observed->value,
        'request_started_at' => '2026-08-27T08:00:01.000000Z',
        'response_received_at' => '2026-08-27T08:00:01.250000Z',
        'http_status' => 200,
        'content_type' => 'application/json',
        'provider_request_id_hmac_sha256' => str_repeat('8', 64),
        'response_body_base64' => base64_encode($response),
        'response_body_sha256' => hash('sha256', $response),
        'response_size_bytes' => strlen($response),
    ];

    return [
        'document' => fakturowniaSignNativeBrokerDocument($envelope, $secretKey),
        'public_key' => sodium_crypto_sign_publickey_from_secretkey($secretKey),
    ];
}

it('verifies a role-separated native broker policy, supervisor attestation and effect result', function (): void {
    $policyFixture = fakturowniaNativeBrokerTrustPolicyFixture();
    $observedAt = new DateTimeImmutable('2026-08-27T08:00:03.000000Z');
    $policy = NativeBrokerTrustPolicy::verify(
        $policyFixture['document'],
        'deployment-policy-1',
        $policyFixture['public_key'],
        $observedAt,
    );
    $attestationFixture = fakturowniaNativeSupervisorAttestationFixture(
        $policyFixture['supervisor_secret_key'],
    );
    $attestation = NativeSupervisorAttestation::fromArray($attestationFixture['document']);

    expect(NativeSupervisorAttestationVerifier::verify(
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('n', 32)),
        str_repeat('2', 64),
        str_repeat('a', 64),
        str_repeat('b', 64),
    ))->toBe($attestation)
        ->and(json_encode($policy, JSON_THROW_ON_ERROR))
        ->toBe('{"native_broker_trust_policy":"[VERIFIED]","public_keys":"[REDACTED]"}')
        ->and(fn () => serialize($policy))->toThrow(LogicException::class);

    $resultFixture = fakturowniaBrokeredEffectResultFixture(
        secretKey: $policyFixture['effect_result_secret_key'],
        supervisorAttestationSha256: $attestation->sha256(),
    );
    $result = BrokeredEffectExecutionResult::fromArray($resultFixture['document']);

    expect(BrokeredEffectExecutionResultVerifier::verify(
        $result,
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('n', 32)),
        str_repeat('2', 64),
        str_repeat('5', 64),
        str_repeat('6', 32),
        str_repeat('7', 64),
    ))->toBe($result);
});

it('rejects policy tampering, role confusion, stale proofs and cross-run bindings', function (): void {
    $policyFixture = fakturowniaNativeBrokerTrustPolicyFixture();
    $observedAt = new DateTimeImmutable('2026-08-27T08:00:03.000000Z');
    $policy = NativeBrokerTrustPolicy::verify(
        $policyFixture['document'],
        'deployment-policy-1',
        $policyFixture['public_key'],
        $observedAt,
    );
    $tamperedPolicy = $policyFixture['document'];
    $tamperedPolicy['envelope']['argv_sha256'] = str_repeat('a', 64);

    expect(fn () => NativeBrokerTrustPolicy::verify(
        $tamperedPolicy,
        'deployment-policy-1',
        $policyFixture['public_key'],
        $observedAt,
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeBrokerTrustPolicy::verify(
            $policyFixture['document'],
            'deployment-policy-2',
            $policyFixture['public_key'],
            $observedAt,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeBrokerTrustPolicy::verify(
            $policyFixture['document'],
            'deployment-policy-1',
            $policyFixture['public_key'],
            new DateTimeImmutable('2026-08-27T09:00:00.000000Z'),
        ))->toThrow(InvalidArgumentException::class);

    $attestationFixture = fakturowniaNativeSupervisorAttestationFixture(
        $policyFixture['supervisor_secret_key'],
    );
    $attestation = NativeSupervisorAttestation::fromArray($attestationFixture['document']);

    expect(fn () => NativeSupervisorAttestationVerifier::verify(
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('x', 32)),
        str_repeat('2', 64),
        str_repeat('a', 64),
        str_repeat('b', 64),
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeSupervisorAttestationVerifier::verify(
            $attestation,
            $policy,
            new DateTimeImmutable('2026-08-27T08:10:00.000000Z'),
            str_repeat('1', 64),
            base64_encode(str_repeat('n', 32)),
            str_repeat('2', 64),
            str_repeat('a', 64),
            str_repeat('b', 64),
        ))->toThrow(InvalidArgumentException::class);

    $roleConfusedFixture = fakturowniaBrokeredEffectResultFixture(
        secretKey: $policyFixture['supervisor_secret_key'],
        signerId: 'native-supervisor-1',
        supervisorAttestationSha256: $attestation->sha256(),
    );
    $roleConfusedResult = BrokeredEffectExecutionResult::fromArray($roleConfusedFixture['document']);

    expect(fn () => BrokeredEffectExecutionResultVerifier::verify(
        $roleConfusedResult,
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('n', 32)),
        str_repeat('2', 64),
        str_repeat('5', 64),
        str_repeat('6', 32),
        str_repeat('7', 64),
    ))->toThrow(InvalidArgumentException::class);

    $resultFixture = fakturowniaBrokeredEffectResultFixture(
        secretKey: $policyFixture['effect_result_secret_key'],
        supervisorAttestationSha256: $attestation->sha256(),
    );
    $result = BrokeredEffectExecutionResult::fromArray($resultFixture['document']);

    expect(fn () => BrokeredEffectExecutionResultVerifier::verify(
        $result,
        $attestation,
        $policy,
        $observedAt,
        str_repeat('1', 64),
        base64_encode(str_repeat('n', 32)),
        str_repeat('2', 64),
        str_repeat('a', 64),
        str_repeat('6', 32),
        str_repeat('7', 64),
    ))->toThrow(InvalidArgumentException::class);
});

it('freezes and verifies the signed native supervisor attestation envelope', function (): void {
    $fixture = fakturowniaNativeSupervisorAttestationFixture();
    $attestation = NativeSupervisorAttestation::fromArray($fixture['document']);
    $signature = base64_decode($attestation->signature, true);

    if (! is_string($signature) || $signature === '') {
        throw new LogicException('The native supervisor signature fixture is invalid.');
    }

    expect(sodium_crypto_sign_verify_detached(
        $signature,
        $attestation->canonicalEnvelope(),
        $fixture['public_key'],
    ))->toBeTrue()
        ->and($attestation->sha256())->toBe(hash('sha256', $attestation->canonical()))
        ->and(json_encode($attestation, JSON_THROW_ON_ERROR))->toBe('{"native_supervisor_attestation":"[REDACTED]"}')
        ->and(fn () => serialize($attestation))->toThrow(LogicException::class);

    foreach ([
        ['probe_uid' => 0],
        ['run_nonce' => base64_encode(str_repeat('n', 31))],
        ['expires_at' => '2026-08-27T08:10:00.000001Z'],
        ['launch_manifest_sha256' => str_repeat('A', 64)],
    ] as $mutation) {
        expect(fn () => NativeSupervisorAttestation::fromArray([
            ...$fixture['document'],
            'envelope' => [...$fixture['document']['envelope'], ...$mutation],
        ]))->toThrow(InvalidArgumentException::class);
    }
});

it('freezes applied, possibly applied, denied and consumed broker result shapes', function (): void {
    foreach (BrokeredEffectDisposition::cases() as $disposition) {
        $fixture = fakturowniaBrokeredEffectResultFixture($disposition);
        $result = BrokeredEffectExecutionResult::fromArray($fixture['document']);
        $signature = base64_decode($result->signature, true);

        if (! is_string($signature) || $signature === '') {
            throw new LogicException('The brokered effect signature fixture is invalid.');
        }

        expect($result->disposition)->toBe($disposition)
            ->and($result->responseBody())->toBe(
                $disposition === BrokeredEffectDisposition::Applied
                    ? '{"id":123,"number":"FV/1/2026"}'
                    : '',
            )
            ->and(sodium_crypto_sign_verify_detached(
                $signature,
                $result->canonicalEnvelope(),
                $fixture['public_key'],
            ))->toBeTrue()
            ->and(json_encode($result, JSON_THROW_ON_ERROR))
            ->toBe('{"brokered_effect_execution_result":"[REDACTED]"}');
    }
});

it('rejects result shapes that overclaim provider execution evidence', function (): void {
    $fixture = fakturowniaBrokeredEffectResultFixture(BrokeredEffectDisposition::Denied);

    foreach ([
        ['request_started_at' => '2026-08-27T08:00:01.000000Z'],
        ['http_status' => 403],
        ['content_type' => 'application/json'],
        ['response_body_base64' => base64_encode('denied')],
        ['response_body_sha256' => str_repeat('9', 64)],
        ['response_size_bytes' => 1],
    ] as $mutation) {
        expect(fn () => BrokeredEffectExecutionResult::fromArray([
            ...$fixture['document'],
            'envelope' => [...$fixture['document']['envelope'], ...$mutation],
        ]))->toThrow(InvalidArgumentException::class);
    }
});

it('frames only one bounded canonical JSON object with an exact length prefix', function (): void {
    $fixture = fakturowniaNativeSupervisorAttestationFixture();
    $frame = NativeBrokerWireFrame::encode($fixture['document']);
    $stream = fopen('php://temp', 'w+b');

    if (! is_resource($stream)) {
        throw new RuntimeException('Cannot open the native broker stream fixture.');
    }

    NativeBrokerWireFrame::writeToStream($stream, $fixture['document']);
    rewind($stream);

    expect(NativeBrokerWireFrame::decode($frame))->toEqual($fixture['document'])
        ->and(NativeBrokerWireFrame::readFromStream($stream))->toEqual($fixture['document'])
        ->and(fn () => NativeBrokerWireFrame::decode($frame."\n"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeBrokerWireFrame::decode("00000002\n{}"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => NativeBrokerWireFrame::decode("0000000d\n{\"b\":1,\"a\":2}"))
        ->toThrow(InvalidArgumentException::class);

    fclose($stream);
});

it('freezes the bounded effect proposal without exposing broker credentials', function (): void {
    $document = [
        'contract' => BrokeredEffectExecutionProposal::Contract,
        'version' => BrokeredEffectExecutionProposal::Version,
        'evidence_contract' => 'fakturownia-invoice-identity-s0.3-v1',
        'effect_id' => str_repeat('6', 32),
        'effect_sequence' => 1,
        'profile' => 'invoice_identity',
        'target_key' => 'primary',
        'capability' => 'invoice.vat.issue',
        'semantic_effect' => 'invoice_create',
        'http_method' => 'POST',
        'endpoint_template' => '/invoices.json',
        'provider_path' => '/invoices.json',
        'request_body_base64' => base64_encode('{"invoice":{"kind":"vat"}}'),
        'connect_timeout_ms' => 1_000,
        'request_timeout_ms' => 5_000,
        'maximum_response_bytes' => 1_048_576,
    ];
    $proposal = BrokeredEffectExecutionProposal::fromArray($document);

    expect($proposal->toArray())->toBe($document)
        ->and($proposal->requestBody())->toBe('{"invoice":{"kind":"vat"}}')
        ->and(hash('sha256', $proposal->canonical()))
        ->toBe('0ba5e1848069aac582a0bc3bc98e0d4cb9cfbc1d956e0d1c5e92dbf7286ebaf1')
        ->and(NativeBrokerWireFrame::decode(NativeBrokerWireFrame::encode($proposal->toArray())))
        ->toEqual($document)
        ->and(json_encode($proposal, JSON_THROW_ON_ERROR))
        ->toBe('{"brokered_effect_execution_proposal":"[REDACTED]"}')
        ->and($proposal->canonical())->not->toContain('api_token')
        ->and($proposal->canonical())->not->toContain('commitment_key');

    foreach ([
        ['provider_path' => '/clients.json'],
        ['target_key' => 'explicit_block'],
        ['request_body_base64' => base64_encode('{"invoice": {"kind":"vat"}}')],
        ['effect_sequence' => 12],
        ['capability' => 'invoice.delete'],
        ['maximum_response_bytes' => 1_048_577],
    ] as $mutation) {
        expect(fn () => BrokeredEffectExecutionProposal::fromArray([...$document, ...$mutation]))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('verifies signed bounded read observations without exposing a provider token', function (): void {
    $policyFixture = fakturowniaNativeBrokerTrustPolicyFixture();
    $observedAt = new DateTimeImmutable('2026-08-27T08:00:03.000000Z');
    $policy = NativeBrokerTrustPolicy::verify(
        $policyFixture['document'],
        'deployment-policy-1',
        $policyFixture['public_key'],
        $observedAt,
    );
    $attestationFixture = fakturowniaNativeSupervisorAttestationFixture(
        $policyFixture['supervisor_secret_key'],
    );
    $attestation = NativeSupervisorAttestation::fromArray($attestationFixture['document']);
    $proposalDocument = [
        'contract' => BrokeredReadObservationProposal::Contract,
        'version' => BrokeredReadObservationProposal::Version,
        'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
        'observation_id' => str_repeat('a', 32),
        'profile' => 'invoice_identity',
        'target_key' => 'primary',
        'capability' => 'invoice.search',
        'http_method' => 'GET',
        'endpoint_template' => '/invoices.json',
        'provider_path' => '/invoices.json?include_positions=true&oid=probe-1234&page=1&per_page=100&period=all',
        'connect_timeout_ms' => 1_000,
        'request_timeout_ms' => 5_000,
        'maximum_response_bytes' => 26_214_400,
    ];
    $proposal = BrokeredReadObservationProposal::fromArray($proposalDocument);
    $resultFixture = fakturowniaBrokeredReadResultFixture(
        $proposal,
        $attestation,
        $policyFixture['effect_result_secret_key'],
    );
    $responseDocument = [
        'contract' => BrokeredReadObservationResponse::Contract,
        'version' => BrokeredReadObservationResponse::Version,
        'result' => $resultFixture['document'],
    ];
    $response = BrokeredReadObservationResponse::fromArray($responseDocument);

    expect(BrokeredReadObservationResultVerifier::verify(
        $response->result,
        $proposal,
        $attestation,
        $policy,
        $observedAt,
    ))->toBe($response->result)
        ->and($response->result->responseBody())->toBe('{"id":123}')
        ->and($proposal->canonical())->not->toContain('api_token')
        ->and(json_encode($response, JSON_THROW_ON_ERROR))
        ->toBe('{"brokered_read_observation_response":"[REDACTED]"}');

    foreach ([
        ['provider_path' => $proposalDocument['provider_path'].'&api_token=secret'],
        ['maximum_response_bytes' => 26_214_401],
        ['target_key' => 'unknown'],
    ] as $mutation) {
        expect(fn () => BrokeredReadObservationProposal::fromArray([
            ...$proposalDocument,
            ...$mutation,
        ]))->toThrow(InvalidArgumentException::class);
    }
});

it('accepts only an exact same-OID pair for concurrent broker execution', function (): void {
    $base = [
        'contract' => BrokeredEffectExecutionProposal::Contract,
        'version' => BrokeredEffectExecutionProposal::Version,
        'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
        'effect_id' => str_repeat('6', 32),
        'effect_sequence' => 1,
        'profile' => 'invoice_identity',
        'target_key' => 'primary',
        'capability' => 'invoice.vat.issue',
        'semantic_effect' => 'invoice_create',
        'http_method' => 'POST',
        'endpoint_template' => '/invoices.json',
        'provider_path' => '/invoices.json',
        'request_body_base64' => base64_encode('{"invoice":{"oid":"same-oid"}}'),
        'connect_timeout_ms' => 1_000,
        'request_timeout_ms' => 5_000,
        'maximum_response_bytes' => 1_048_576,
    ];
    $document = [
        'contract' => ConcurrentBrokeredEffectExecutionProposal::Contract,
        'version' => ConcurrentBrokeredEffectExecutionProposal::Version,
        'proposals' => [
            $base,
            [...$base, 'effect_id' => str_repeat('7', 32), 'effect_sequence' => 2],
        ],
    ];
    $proposal = ConcurrentBrokeredEffectExecutionProposal::fromArray($document);

    expect($proposal->toArray())->toBe($document)
        ->and(json_encode($proposal, JSON_THROW_ON_ERROR))
        ->toBe('{"concurrent_brokered_effect_execution_proposal":"[REDACTED]"}');

    $tampered = $document;
    $tampered['proposals'][1]['request_body_base64'] = base64_encode('{"invoice":{"oid":"other-oid"}}');

    expect(fn () => ConcurrentBrokeredEffectExecutionProposal::fromArray($tampered))
        ->toThrow(InvalidArgumentException::class);
});

it('binds the broker-created descriptor to the signed execution result', function (): void {
    $policyFixture = fakturowniaNativeBrokerTrustPolicyFixture();
    $policy = NativeBrokerTrustPolicy::verify(
        $policyFixture['document'],
        'deployment-policy-1',
        $policyFixture['public_key'],
        new DateTimeImmutable('2026-08-27T08:00:03.000000Z'),
    );
    $descriptor = LiveEffectDescriptor::fromArray([
        'contract' => 'cieplik206.fakturownia.live-effect-descriptor',
        'version' => '1',
        'evidence_contract' => 'fakturownia-invoice-identity-s0.3-v1',
        'run_id' => str_repeat('a', 32),
        'effect_id' => str_repeat('6', 32),
        'effect_sequence' => 1,
        'profile' => 'invoice_identity',
        'target_key' => 'primary',
        'capability' => 'invoice.vat.issue',
        'semantic_effect' => 'invoice_create',
        'http_method' => 'POST',
        'endpoint_template' => '/invoices.json',
        'commitment_scheme' => 'hmac-sha256-ephemeral-run-key-v1',
        'target_origin_hmac_sha256' => str_repeat('7', 64),
        'operation_identity_hmac_sha256' => str_repeat('8', 64),
        'request_body_hmac_sha256' => str_repeat('9', 64),
        'request_body_size_bytes' => 26,
        'request_body_policy' => 'required_non_empty',
        'launch_manifest_sha256' => str_repeat('1', 64),
        'supervisor_attestation_sha256' => str_repeat('4', 64),
        'broker_policy_sha256' => str_repeat('3', 64),
        'authorization_set_sha256' => str_repeat('2', 64),
        'authorization_bundle_sha256' => str_repeat('d', 64),
        'probe_plan_sha256' => str_repeat('e', 64),
        'claim_request_sha256' => str_repeat('b', 64),
        'consumption_receipt_sha256' => str_repeat('c', 64),
        'claim_nonce' => base64_encode(str_repeat('q', 32)),
        'run_started_at' => '2026-08-27T08:00:00.000000Z',
        'connect_timeout_ms' => 1_000,
        'request_timeout_ms' => 5_000,
        'maximum_response_bytes' => 1_048_576,
    ]);
    $result = fakturowniaBrokeredEffectResultFixture(
        secretKey: $policyFixture['effect_result_secret_key'],
        effectDescriptorSha256: $descriptor->sha256(),
    );
    $resultObject = BrokeredEffectExecutionResult::fromArray($result['document']);
    $receipt = fakturowniaBrokeredEffectReceiptFixture(
        $descriptor,
        $resultObject,
        $policyFixture['effect_result_secret_key'],
    );
    $document = [
        'contract' => BrokeredEffectExecutionResponse::Contract,
        'version' => BrokeredEffectExecutionResponse::Version,
        'descriptor' => $descriptor->toArray(),
        'result' => $result['document'],
        'receipt' => $receipt['document'],
    ];
    $response = BrokeredEffectExecutionResponse::fromArray($document);
    $policy->assertEffectExecutionReceiptSignature($response->receipt);

    expect($response->toArray())->toBe($document)
        ->and($response->result->effectDescriptorSha256)->toBe($response->descriptor->sha256())
        ->and($response->receipt->canonical())->not->toContain(
            'response_body_base64',
            'FV/1/2026',
        )
        ->and(json_encode($response, JSON_THROW_ON_ERROR))
        ->toBe('{"brokered_effect_execution_response":"[REDACTED]"}');

    $tampered = $document;
    $tampered['descriptor']['effect_id'] = str_repeat('d', 32);

    expect(fn () => BrokeredEffectExecutionResponse::fromArray($tampered))
        ->toThrow(InvalidArgumentException::class);

    $tamperedReceipt = $receipt['document'];
    $tamperedReceipt['signature'] = base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));

    expect(fn () => $policy->assertEffectExecutionReceiptSignature(
        BrokeredEffectExecutionReceipt::fromArray($tamperedReceipt),
    ))->toThrow(InvalidArgumentException::class);
});

it('executes one proposal through a fully verified native broker session', function (): void {
    $policyFixture = fakturowniaNativeBrokerTrustPolicyFixture();
    $bundleFixture = fakturowniaNativeAuthorizationBundleFixture();
    $attestationFixture = fakturowniaNativeSupervisorAttestationFixture(
        $policyFixture['supervisor_secret_key'],
        authorizationSetSha256: $bundleFixture['authorization_set_sha256'],
        authorizationBundleSha256: $bundleFixture['authorization_bundle_sha256'],
        probePlanSha256: $bundleFixture['probe_plan_sha256'],
    );
    $attestation = NativeSupervisorAttestation::fromArray($attestationFixture['document']);
    $handoff = [
        'contract' => NativeBrokerAuthorityHandoff::Contract,
        'version' => NativeBrokerAuthorityHandoff::Version,
        'trust_policy' => $policyFixture['document'],
        'supervisor_attestation' => $attestationFixture['document'],
        'authorization' => [
            'run_id' => str_repeat('a', 32),
            'run_started_at' => '2026-08-27T07:59:59.000000Z',
            'evidence_contract' => 'fakturownia-invoice-identity-s0.3-v1',
            'profiles' => ['invoice_identity'],
            'claim_nonce' => base64_encode(str_repeat('q', 32)),
            'authorization_set_sha256' => $bundleFixture['authorization_set_sha256'],
            'claim_request_sha256' => $bundleFixture['claim_request_sha256'],
            'consumption_receipt_sha256' => $bundleFixture['consumption_receipt_sha256'],
            'authorization_bundle_sha256' => $bundleFixture['authorization_bundle_sha256'],
            'probe_plan_sha256' => $bundleFixture['probe_plan_sha256'],
        ],
        'authorization_bundle' => $bundleFixture['document'],
    ];
    $proposalDocument = [
        'contract' => BrokeredEffectExecutionProposal::Contract,
        'version' => BrokeredEffectExecutionProposal::Version,
        'evidence_contract' => 'fakturownia-invoice-identity-s0.3-v1',
        'effect_id' => str_repeat('6', 32),
        'effect_sequence' => 1,
        'profile' => 'invoice_identity',
        'target_key' => 'primary',
        'capability' => 'invoice.vat.issue',
        'semantic_effect' => 'invoice_create',
        'http_method' => 'POST',
        'endpoint_template' => '/invoices.json',
        'provider_path' => '/invoices.json',
        'request_body_base64' => base64_encode('{"invoice":{"kind":"vat"}}'),
        'connect_timeout_ms' => 1_000,
        'request_timeout_ms' => 5_000,
        'maximum_response_bytes' => 1_048_576,
    ];
    $proposal = BrokeredEffectExecutionProposal::fromArray($proposalDocument);
    $descriptor = LiveEffectDescriptor::fromArray([
        'contract' => 'cieplik206.fakturownia.live-effect-descriptor',
        'version' => '1',
        'evidence_contract' => 'fakturownia-invoice-identity-s0.3-v1',
        'run_id' => str_repeat('a', 32),
        'effect_id' => str_repeat('6', 32),
        'effect_sequence' => 1,
        'profile' => 'invoice_identity',
        'target_key' => 'primary',
        'capability' => 'invoice.vat.issue',
        'semantic_effect' => 'invoice_create',
        'http_method' => 'POST',
        'endpoint_template' => '/invoices.json',
        'commitment_scheme' => 'hmac-sha256-ephemeral-run-key-v1',
        'target_origin_hmac_sha256' => str_repeat('7', 64),
        'operation_identity_hmac_sha256' => str_repeat('8', 64),
        'request_body_hmac_sha256' => str_repeat('9', 64),
        'request_body_size_bytes' => 26,
        'request_body_policy' => 'required_non_empty',
        'launch_manifest_sha256' => str_repeat('1', 64),
        'supervisor_attestation_sha256' => $attestation->sha256(),
        'broker_policy_sha256' => str_repeat('3', 64),
        'authorization_set_sha256' => $bundleFixture['authorization_set_sha256'],
        'authorization_bundle_sha256' => $bundleFixture['authorization_bundle_sha256'],
        'probe_plan_sha256' => $bundleFixture['probe_plan_sha256'],
        'claim_request_sha256' => $bundleFixture['claim_request_sha256'],
        'consumption_receipt_sha256' => $bundleFixture['consumption_receipt_sha256'],
        'claim_nonce' => base64_encode(str_repeat('q', 32)),
        'run_started_at' => '2026-08-27T07:59:59.000000Z',
        'connect_timeout_ms' => 1_000,
        'request_timeout_ms' => 5_000,
        'maximum_response_bytes' => 1_048_576,
    ]);
    $result = fakturowniaBrokeredEffectResultFixture(
        secretKey: $policyFixture['effect_result_secret_key'],
        supervisorAttestationSha256: $attestation->sha256(),
        effectDescriptorSha256: $descriptor->sha256(),
        authorizationSetSha256: $bundleFixture['authorization_set_sha256'],
    );
    $receipt = fakturowniaBrokeredEffectReceiptFixture(
        $descriptor,
        BrokeredEffectExecutionResult::fromArray($result['document']),
        $policyFixture['effect_result_secret_key'],
    );
    $response = [
        'contract' => BrokeredEffectExecutionResponse::Contract,
        'version' => BrokeredEffectExecutionResponse::Version,
        'descriptor' => $descriptor->toArray(),
        'result' => $result['document'],
        'receipt' => $receipt['document'],
    ];
    $input = fopen('php://temp', 'w+b');
    $output = fopen('php://temp', 'w+b');

    if (! is_resource($input) || ! is_resource($output)) {
        throw new RuntimeException('Cannot open native broker session fixtures.');
    }

    NativeBrokerWireFrame::writeToStream($input, $handoff);
    NativeBrokerWireFrame::writeToStream($input, $response);
    rewind($input);
    $session = NativeBrokerSession::fromStreams(
        $input,
        $output,
        'deployment-policy-1',
        $policyFixture['public_key'],
        str_repeat('1', 64),
        str_repeat('4', 64),
        new DateTimeImmutable('2026-08-27T08:00:03.000000Z'),
    );
    $execution = $session->execute(
        $proposal,
        new DateTimeImmutable('2026-08-27T08:00:03.000000Z'),
    );
    rewind($output);

    expect($execution->descriptor->sha256())->toBe($descriptor->sha256())
        ->and(NativeBrokerWireFrame::readFromStream($output))->toEqual($proposalDocument)
        ->and(json_encode($session, JSON_THROW_ON_ERROR))->toBe('{"native_broker_session":"[VERIFIED]"}');

    fclose($input);
    fclose($output);
});

it('maps the exact S0.3 Saloon surface to token-free native broker proposals', function (): void {
    $policyFixture = fakturowniaNativeBrokerTrustPolicyFixture();
    $bundleFixture = fakturowniaNativeAuthorizationBundleFixture();
    $attestationFixture = fakturowniaNativeSupervisorAttestationFixture(
        $policyFixture['supervisor_secret_key'],
        authorizationSetSha256: $bundleFixture['authorization_set_sha256'],
        authorizationBundleSha256: $bundleFixture['authorization_bundle_sha256'],
        probePlanSha256: $bundleFixture['probe_plan_sha256'],
    );
    $handoff = [
        'contract' => NativeBrokerAuthorityHandoff::Contract,
        'version' => NativeBrokerAuthorityHandoff::Version,
        'trust_policy' => $policyFixture['document'],
        'supervisor_attestation' => $attestationFixture['document'],
        'authorization' => [
            'run_id' => str_repeat('a', 32),
            'run_started_at' => '2026-08-27T07:59:59.000000Z',
            'evidence_contract' => SignedLiveProbeAuthorization::InvoiceIdentityEvidenceContract,
            'profiles' => ['invoice_identity'],
            'claim_nonce' => base64_encode(str_repeat('q', 32)),
            'authorization_set_sha256' => $bundleFixture['authorization_set_sha256'],
            'claim_request_sha256' => $bundleFixture['claim_request_sha256'],
            'consumption_receipt_sha256' => $bundleFixture['consumption_receipt_sha256'],
            'authorization_bundle_sha256' => $bundleFixture['authorization_bundle_sha256'],
            'probe_plan_sha256' => $bundleFixture['probe_plan_sha256'],
        ],
        'authorization_bundle' => $bundleFixture['document'],
    ];
    $input = fopen('php://temp', 'w+b');
    $output = fopen('php://temp', 'w+b');

    if (! is_resource($input) || ! is_resource($output)) {
        throw new RuntimeException('Cannot open native broker adapter fixtures.');
    }

    NativeBrokerWireFrame::writeToStream($input, $handoff);
    rewind($input);
    $session = NativeBrokerSession::fromStreams(
        $input,
        $output,
        'deployment-policy-1',
        $policyFixture['public_key'],
        str_repeat('1', 64),
        str_repeat('4', 64),
        new DateTimeImmutable('2026-08-27T08:00:03.000000Z'),
    );
    $sender = new NativeBrokerSaloonSender($session);
    $connector = (new FakturowniaProbeConnector(
        'https://primary.s03-native.invalid',
        1_000,
        5_000,
    ))->withNativeBrokerSender($sender);
    $map = new ReflectionMethod(NativeBrokerSaloonSender::class, 'map');
    $create = $map->invoke($sender, $connector->createPendingRequest(new CreateProbeInvoiceRequest(
        NativeBrokerSaloonSender::TokenSentinel,
        ['kind' => 'vat', 'oid' => 'probe-1234'],
    )));
    $search = $map->invoke($sender, $connector->createPendingRequest(new SearchProbeInvoicesRequest(
        NativeBrokerSaloonSender::TokenSentinel,
        'probe-1234',
        1,
    )));

    if (! is_array($create) || ! is_array($search)) {
        throw new RuntimeException('The native broker adapter did not return proposal arrays.');
    }

    expect($create['kind'])->toBe('effect')
        ->and(base64_decode($create['proposal']['request_body_base64'], true))
        ->toBe('{"invoice":{"kind":"vat","oid":"probe-1234"}}')
        ->and(CanonicalCodec::encode($create))->not->toContain(
            NativeBrokerSaloonSender::TokenSentinel,
            'api_token',
        )
        ->and($search['kind'])->toBe('read')
        ->and($search['proposal']['provider_path'])
        ->toBe('/invoices.json?include_positions=true&oid=probe-1234&page=1&per_page=100&period=all')
        ->and(CanonicalCodec::encode($search))->not->toContain(
            NativeBrokerSaloonSender::TokenSentinel,
            'api_token',
        )
        ->and(fn () => $map->invoke(
            $sender,
            $connector->createPendingRequest(new CreateProbeInvoiceRequest(
                'caller-supplied-secret',
                ['kind' => 'vat', 'oid' => 'probe-1234'],
            )),
        ))->toThrow(InvalidArgumentException::class, 'exact token-free invoice body');

    fclose($input);
    fclose($output);
});

it('verifies the complete S0.4 handoff and maps its separate create and explicit-send effects', function (): void {
    $policyFixture = fakturowniaNativeBrokerTrustPolicyFixture();
    $bundleFixture = fakturowniaNativeKsefAuthorizationBundleFixture();
    $attestationFixture = fakturowniaNativeSupervisorAttestationFixture(
        $policyFixture['supervisor_secret_key'],
        authorizationSetSha256: $bundleFixture['authorization_set_sha256'],
        authorizationBundleSha256: $bundleFixture['authorization_bundle_sha256'],
        probePlanSha256: $bundleFixture['probe_plan_sha256'],
    );
    $handoff = [
        'contract' => NativeBrokerAuthorityHandoff::Contract,
        'version' => NativeBrokerAuthorityHandoff::Version,
        'trust_policy' => $policyFixture['document'],
        'supervisor_attestation' => $attestationFixture['document'],
        'authorization' => [
            'run_id' => str_repeat('a', 32),
            'run_started_at' => '2026-08-27T07:59:59.000000Z',
            'evidence_contract' => SignedLiveProbeAuthorization::KsefDemoEvidenceContract,
            'profiles' => ['auto_block', 'auto_persist', 'explicit_block', 'explicit_persist'],
            'claim_nonce' => base64_encode(str_repeat('q', 32)),
            'authorization_set_sha256' => $bundleFixture['authorization_set_sha256'],
            'claim_request_sha256' => $bundleFixture['claim_request_sha256'],
            'consumption_receipt_sha256' => $bundleFixture['consumption_receipt_sha256'],
            'authorization_bundle_sha256' => $bundleFixture['authorization_bundle_sha256'],
            'probe_plan_sha256' => $bundleFixture['probe_plan_sha256'],
        ],
        'authorization_bundle' => $bundleFixture['document'],
    ];
    $input = fopen('php://temp', 'w+b');
    $output = fopen('php://temp', 'w+b');

    if (! is_resource($input) || ! is_resource($output)) {
        throw new RuntimeException('Cannot open native KSeF broker fixtures.');
    }

    NativeBrokerWireFrame::writeToStream($input, $handoff);
    rewind($input);
    $session = NativeBrokerSession::fromStreams(
        $input,
        $output,
        'deployment-policy-1',
        $policyFixture['public_key'],
        str_repeat('1', 64),
        str_repeat('4', 64),
        new DateTimeImmutable('2026-08-27T08:00:03.000000Z'),
    );
    $configuration = KsefDemoProbeConfiguration::fromNativeBrokerSession($session);
    $probe = KsefDemoContractProbe::forNativeBrokerSession($session);
    $probe->assertRealProviderTransportOrigin();
    $profile = $configuration->profiles['explicit_block'];
    $sender = $configuration->nativeBrokerSender();
    $connector = new KsefDemoConnector(
        $profile->endpoint->baseUrl,
        $configuration->connectTimeoutMs,
        $configuration->requestTimeoutMs,
        nativeBrokerSender: $sender,
    );
    $map = new ReflectionMethod(NativeBrokerSaloonSender::class, 'map');
    $create = $map->invoke($sender, $connector->createPendingRequest(new CreateKsefDemoInvoiceRequest(
        NativeBrokerSaloonSender::TokenSentinel,
        $profile->validInvoice,
    )));
    $send = $map->invoke($sender, $connector->createPendingRequest(new SendKsefDemoInvoiceRequest(
        NativeBrokerSaloonSender::TokenSentinel,
        '123',
    )));

    if (! is_array($create) || ! is_array($send)) {
        throw new RuntimeException('The native KSeF adapter did not return proposal arrays.');
    }

    expect(array_keys($configuration->profiles))->toBe([
        'explicit_block',
        'explicit_persist',
        'auto_block',
        'auto_persist',
    ])->and($configuration->usesNativeBroker())->toBeTrue()
        ->and($configuration->nativeBrokerSession())->toBe($session)
        ->and($probe->nativeBrokerSession())->toBe($session)
        ->and($create['kind'])->toBe('effect')
        ->and($create['proposal']['evidence_contract'])
        ->toBe(SignedLiveProbeAuthorization::KsefDemoEvidenceContract)
        ->and($create['proposal']['target_key'])->toBe('explicit_block')
        ->and($create['proposal']['capability'])->toBe('contract_probe.invoice.fixture.issue')
        ->and(CanonicalCodec::encode($create))->not->toContain(
            NativeBrokerSaloonSender::TokenSentinel,
            'api_token',
        )
        ->and($send['kind'])->toBe('effect')
        ->and($send['proposal']['capability'])->toBe('invoice.ksef.ensure_accepted')
        ->and($send['proposal']['http_method'])->toBe('GET')
        ->and($send['proposal']['provider_path'])->toBe('/invoices/123.json?send_to_ksef=yes')
        ->and($send['proposal']['request_body_base64'])->toBe('');

    fclose($input);
    fclose($output);
});
