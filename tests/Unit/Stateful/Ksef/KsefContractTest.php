<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Ksef\KnownKsefStatus;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefAccountSetting;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefAccountSettingsSnapshot;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefConnectionProfile;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefDoctorIssue;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefDoctorPreflight;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefDoctorResult;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefOwnership;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefSettingEvidenceSource;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefSettingObservation;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefSettingsEvidenceVerifier;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefStatusCategory;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefValidationMode;
use Cieplik206\Fakturownia\Stateful\Ksef\OpenKsefStatus;

function ksefConnectionFingerprint(): string
{
    return hash('sha256', 'connection:marruni');
}

function ksefVerifier(): KsefSettingsEvidenceVerifier
{
    return new KsefSettingsEvidenceVerifier(ksefEvidenceKey());
}

function ksefEvidenceKey(): string
{
    return str_repeat('trusted-ksef-evidence-key-', 2);
}

function ksefOnlineSetting(KsefAccountSetting $setting, string|bool|null $value): KsefSettingObservation
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return ksefSignedSetting(
        $setting,
        $value,
        KsefSettingEvidenceSource::OnlineVerified,
        $now->modify('-1 minute'),
        $now->modify('+10 minutes'),
    );
}

function ksefSignedSetting(
    KsefAccountSetting $setting,
    string|bool|null $value,
    KsefSettingEvidenceSource $source,
    DateTimeImmutable $observedAt,
    DateTimeImmutable $validUntil,
): KsefSettingObservation {
    $unsigned = KsefSettingObservation::fromSignedEvidence(
        ksefConnectionFingerprint(),
        $setting,
        $value,
        $source,
        $observedAt,
        $validUntil,
        str_repeat('0', 64),
    );

    return KsefSettingObservation::fromSignedEvidence(
        ksefConnectionFingerprint(),
        $setting,
        $value,
        $source,
        $observedAt,
        $validUntil,
        hash_hmac('sha256', $unsigned->evidencePayload(), ksefEvidenceKey()),
    );
}

function ksefEmptySerializedObject(string $className): string
{
    return sprintf('O:%d:"%s":0:{}', strlen($className), $className);
}

function ksefSettings(string|bool|null $autoSend, bool $validate, bool $buyerCompany): KsefAccountSettingsSnapshot
{
    return new KsefAccountSettingsSnapshot(
        ksefOnlineSetting(KsefAccountSetting::GovAutoSendMode, $autoSend),
        ksefOnlineSetting(KsefAccountSetting::ValidateInvoicesForGov, $validate),
        ksefOnlineSetting(KsefAccountSetting::BuyerCompany, $buyerCompany),
    );
}

it('keeps known and future KSeF statuses as open typed data', function (): void {
    $success = new OpenKsefStatus('demo_ok');
    $uncertain = new OpenKsefStatus('status_check_error');
    $future = new OpenKsefStatus('provider_future_2027');

    expect($success->known())->toBe(KnownKsefStatus::DemoOk)
        ->and($success->category())->toBe(KsefStatusCategory::Succeeded)
        ->and($success->isTerminal())->toBeFalse()
        ->and($success->isTerminal('KSEF-GOV-ID'))->toBeTrue()
        ->and($success->isTerminal(' KSEF-GOV-ID'))->toBeFalse()
        ->and($success->category()->isTerminal())->toBeFalse()
        ->and($uncertain->category())->toBe(KsefStatusCategory::StatusCheckError)
        ->and($uncertain->isTerminal())->toBeFalse()
        ->and($future->known())->toBeNull()
        ->and($future->category())->toBe(KsefStatusCategory::Unknown)
        ->and($future->isTerminal())->toBeFalse()
        ->and(json_encode($future, JSON_THROW_ON_ERROR))->toBe('"provider_future_2027"');
});

it('classifies every explicit DEMO KSeF status without inventing terminal success', function (
    string $raw,
    KnownKsefStatus $known,
    KsefStatusCategory $category,
    bool $terminalWithoutGovernmentId,
): void {
    $status = new OpenKsefStatus($raw);

    expect($status->known())->toBe($known)
        ->and($status->category())->toBe($category)
        ->and($status->isTerminal())->toBe($terminalWithoutGovernmentId);
})->with([
    'send error' => ['demo_send_error', KnownKsefStatus::DemoSendError, KsefStatusCategory::TechnicalError, false],
    'server error' => ['demo_server_error', KnownKsefStatus::DemoServerError, KsefStatusCategory::TechnicalError, false],
    'not applicable' => ['demo_not_applicable', KnownKsefStatus::DemoNotApplicable, KsefStatusCategory::NotApplicable, true],
    'not connected' => ['demo_not_connected', KnownKsefStatus::DemoNotConnected, KsefStatusCategory::ConfigurationBlocked, false],
]);

it('rejects malformed remote KSeF statuses', function (string $status): void {
    expect(fn (): OpenKsefStatus => new OpenKsefStatus($status))
        ->toThrow(InvalidArgumentException::class);
})->with(['', ' status', 'status ', "status\nother", str_repeat('a', 129), 'żółty']);

it('keeps explicit and provider auto-send ownership mutually exclusive', function (): void {
    $explicit = KsefConnectionProfile::explicitSdk(
        ksefConnectionFingerprint(),
        KsefValidationMode::BlockInvalid,
    );
    $automatic = KsefConnectionProfile::providerAutoSend(
        ksefConnectionFingerprint(),
        KsefValidationMode::PersistWithErrors,
        'after_issue',
        true,
    );

    expect($explicit->ownership)->toBe(KsefOwnership::ExplicitSdk)
        ->and($explicit->ownership->permitsExplicitSend())->toBeTrue()
        ->and($explicit->isInitialPilotProfile())->toBeTrue()
        ->and($automatic->ownership)->toBe(KsefOwnership::ProviderAutoSend)
        ->and($automatic->ownership->permitsExplicitSend())->toBeFalse()
        ->and($automatic->isInitialPilotProfile())->toBeFalse();

    expect(fn (): KsefConnectionProfile => new KsefConnectionProfile(
        ksefConnectionFingerprint(),
        KsefOwnership::ExplicitSdk,
        KsefValidationMode::BlockInvalid,
        'after_issue',
        null,
    ))->toThrow(InvalidArgumentException::class);
});

it('never turns caller-provided settings into an explicit send authorization', function (): void {
    $profile = KsefConnectionProfile::explicitSdk(
        ksefConnectionFingerprint(),
        KsefValidationMode::BlockInvalid,
    );
    $result = (new KsefDoctorPreflight(ksefVerifier()))->inspect(
        $profile,
        ksefSettings(null, true, true),
    );

    expect($result->passes())->toBeTrue()
        ->and($result->permitsObservation())->toBeTrue()
        ->and($result->permitsExplicitSend())->toBeFalse()
        ->and($result->issues)->toBe([]);
});

it('keeps provider auto-send strictly observe-only after a successful doctor check', function (): void {
    $profile = KsefConnectionProfile::providerAutoSend(
        ksefConnectionFingerprint(),
        KsefValidationMode::PersistWithErrors,
        'after_issue',
        false,
    );
    $result = (new KsefDoctorPreflight(ksefVerifier()))->inspect(
        $profile,
        ksefSettings('after_issue', false, false),
    );

    expect($result->passes())->toBeTrue()
        ->and($result->permitsObservation())->toBeTrue()
        ->and($result->permitsExplicitSend())->toBeFalse();
});

it('fails closed on account-setting mismatch and unsupported explicit profiles', function (): void {
    $profile = KsefConnectionProfile::explicitSdk(
        ksefConnectionFingerprint(),
        KsefValidationMode::PersistWithErrors,
    );
    $result = (new KsefDoctorPreflight(ksefVerifier()))->inspect(
        $profile,
        ksefSettings('unexpected_auto_send', true, true),
    );

    expect($result->passes())->toBeFalse()
        ->and($result->permitsExplicitSend())->toBeFalse()
        ->and($result->issues)->toContain(KsefDoctorIssue::GovAutoSendModeMismatch)
        ->and($result->issues)->toContain(KsefDoctorIssue::ValidateInvoicesForGovMismatch)
        ->and($result->issues)->toContain(KsefDoctorIssue::PilotProfileUnsupported);
});

it('requires fresh bounded fingerprint evidence for operator-attested settings', function (): void {
    $beforeAttestation = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $attestedAutoSend = ksefVerifier()->attestOperator(
        ksefConnectionFingerprint(),
        KsefAccountSetting::GovAutoSendMode,
        null,
    );
    $afterAttestation = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $settings = new KsefAccountSettingsSnapshot(
        $attestedAutoSend,
        ksefOnlineSetting(KsefAccountSetting::ValidateInvoicesForGov, true),
        ksefOnlineSetting(KsefAccountSetting::BuyerCompany, true),
    );
    $doctor = new KsefDoctorPreflight(ksefVerifier());
    $profile = KsefConnectionProfile::explicitSdk(
        ksefConnectionFingerprint(),
        KsefValidationMode::BlockInvalid,
    );

    expect($doctor->inspect($profile, $settings)->passes())
        ->toBeTrue()
        ->and($attestedAutoSend->observedAt >= $beforeAttestation)->toBeTrue()
        ->and($attestedAutoSend->observedAt <= $afterAttestation)->toBeTrue()
        ->and($attestedAutoSend->validUntil->getTimestamp() - $attestedAutoSend->observedAt->getTimestamp())
        ->toBe(KsefSettingEvidenceSource::OperatorAttested->maximumTtlSeconds())
        ->and((new ReflectionMethod(KsefSettingsEvidenceVerifier::class, 'attestOperator'))->getNumberOfParameters())->toBe(3)
        ->and((new ReflectionMethod(KsefDoctorResult::class, '__construct'))->isPrivate())->toBeTrue();

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiredSettings = new KsefAccountSettingsSnapshot(
        ksefSignedSetting(
            KsefAccountSetting::GovAutoSendMode,
            null,
            KsefSettingEvidenceSource::OperatorAttested,
            $now->modify('-15 minutes'),
            $now->modify('-10 minutes'),
        ),
        ksefOnlineSetting(KsefAccountSetting::ValidateInvoicesForGov, true),
        ksefOnlineSetting(KsefAccountSetting::BuyerCompany, true),
    );

    expect($doctor->inspect($profile, $expiredSettings)->issues)
        ->toContain(KsefDoctorIssue::EvidenceExpired)
        ->toContain(KsefDoctorIssue::EvidenceUnverified);

    expect(fn (): KsefSettingObservation => KsefSettingObservation::fromSignedEvidence(
        ksefConnectionFingerprint(),
        KsefAccountSetting::GovAutoSendMode,
        null,
        KsefSettingEvidenceSource::OperatorAttested,
        $now,
        $now,
        str_repeat('0', 64),
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): KsefSettingObservation => KsefSettingObservation::fromSignedEvidence(
            ksefConnectionFingerprint(),
            KsefAccountSetting::GovAutoSendMode,
            null,
            KsefSettingEvidenceSource::OperatorAttested,
            $now,
            $now->modify('+11 minutes'),
            str_repeat('0', 64),
        ))->toThrow(InvalidArgumentException::class)
        ->and((new ReflectionMethod(KsefSettingObservation::class, '__construct'))->isPrivate())->toBeTrue();
});

it('rejects future-dated evidence at the package-owned verification instant', function (): void {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $future = ksefSignedSetting(
        KsefAccountSetting::GovAutoSendMode,
        null,
        KsefSettingEvidenceSource::OperatorAttested,
        $now->modify('+1 year'),
        $now->modify('+1 year +5 minutes'),
    );

    expect(ksefVerifier()->verifies($future))->toBeFalse()
        ->and($future->isFreshAt($now))->toBeFalse();
});

it('rejects forged or cross-connection KSeF setting evidence before authorizing an effect', function (): void {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $forged = static fn (KsefAccountSetting $setting, string|bool|null $value): KsefSettingObservation => KsefSettingObservation::fromSignedEvidence(
        ksefConnectionFingerprint(),
        $setting,
        $value,
        KsefSettingEvidenceSource::OnlineVerified,
        $now->modify('-1 minute'),
        $now->modify('+5 minutes'),
        str_repeat('a', 64),
    );
    $settings = new KsefAccountSettingsSnapshot(
        $forged(KsefAccountSetting::GovAutoSendMode, null),
        $forged(KsefAccountSetting::ValidateInvoicesForGov, true),
        $forged(KsefAccountSetting::BuyerCompany, true),
    );
    $otherConnectionProfile = KsefConnectionProfile::explicitSdk(
        hash('sha256', 'connection:other'),
        KsefValidationMode::BlockInvalid,
    );
    $doctor = new KsefDoctorPreflight(ksefVerifier());

    expect($doctor->inspect(
        KsefConnectionProfile::explicitSdk(ksefConnectionFingerprint(), KsefValidationMode::BlockInvalid),
        $settings,
    )->issues)->toContain(KsefDoctorIssue::EvidenceUnverified)
        ->and($doctor->inspect($otherConnectionProfile, $settings)->issues)
        ->toContain(KsefDoctorIssue::EvidenceScopeMismatch)
        ->and($doctor->inspect($otherConnectionProfile, $settings)->permitsExplicitSend())->toBeFalse();
});

it('redacts and seals the KSeF evidence signing authority', function (): void {
    $verifier = ksefVerifier();

    expect(json_encode($verifier, JSON_THROW_ON_ERROR))->toBe('{"hmac_key":"[REDACTED]"}')
        ->and(print_r($verifier, true))->not->toContain('trusted-ksef-evidence-key')
        ->and(get_class_methods($verifier))->not->toContain('attest')
        ->and(fn (): string => serialize($verifier))->toThrow(LogicException::class);
});

it('blocks native serialization bypasses for every KSeF authorization value object', function (): void {
    $profile = KsefConnectionProfile::explicitSdk(
        ksefConnectionFingerprint(),
        KsefValidationMode::BlockInvalid,
    );
    $observation = ksefOnlineSetting(KsefAccountSetting::GovAutoSendMode, null);
    $settings = ksefSettings(null, true, true);
    $doctorResult = KsefDoctorResult::inspect($profile, $settings, ksefVerifier());

    foreach ([$profile, $observation, $settings, $doctorResult] as $sealedValue) {
        expect(fn (): string => serialize($sealedValue))->toThrow(LogicException::class)
            ->and(fn (): mixed => unserialize(ksefEmptySerializedObject($sealedValue::class)))
            ->toThrow(LogicException::class);
    }
});

it('rejects a setting signed for a different canonical snapshot slot', function (): void {
    expect(fn (): KsefAccountSettingsSnapshot => new KsefAccountSettingsSnapshot(
        ksefOnlineSetting(KsefAccountSetting::BuyerCompany, true),
        ksefOnlineSetting(KsefAccountSetting::ValidateInvoicesForGov, true),
        ksefOnlineSetting(KsefAccountSetting::GovAutoSendMode, null),
    ))->toThrow(InvalidArgumentException::class);
});
