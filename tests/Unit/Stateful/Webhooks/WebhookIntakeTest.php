<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Webhooks\DeferredWebhookCapabilityGate;
use Cieplik206\Fakturownia\Laravel\Webhooks\DeferredWebhookSignatureVerifier;
use Cieplik206\Fakturownia\Laravel\Webhooks\ReceiveWebhookAction;
use Cieplik206\Fakturownia\Laravel\Webhooks\RecordWebhookHintAction;
use Cieplik206\Fakturownia\Laravel\Webhooks\SodiumWebhookPayloadProtector;
use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookClock;
use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookInboxRepository;
use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookSignatureVerifier;
use Cieplik206\Fakturownia\Stateful\Webhooks\Exceptions\WebhookCapabilityDeferred;
use Cieplik206\Fakturownia\Stateful\Webhooks\ProviderWebhookDeliveryId;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookDelivery;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookHintTrust;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookInboxEntry;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookInboxReceipt;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookSignatureVerification;

final class S814UnitClock implements WebhookClock
{
    public function __construct(public DateTimeImmutable $current) {}

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }
}

final readonly class S814UnitSignatureVerifier implements WebhookSignatureVerifier
{
    public function __construct(private WebhookSignatureVerification $verification) {}

    public function verify(WebhookDelivery $delivery): WebhookSignatureVerification
    {
        return $this->verification;
    }
}

final class S814UnitInboxRepository implements WebhookInboxRepository
{
    public ?WebhookInboxEntry $entry = null;

    public int $stores = 0;

    public function store(WebhookInboxEntry $entry, int $fallbackDeduplicationWindowSeconds): WebhookInboxReceipt
    {
        $this->entry = $entry;
        $this->stores++;

        return new WebhookInboxReceipt(
            $entry->id,
            $entry->signatureVerification->trust(),
            false,
            1,
            $entry->receivedAt,
            $entry->receivedAt,
        );
    }
}

function s814UnitDelivery(?ProviderWebhookDeliveryId $deliveryId = null, string $body = '{"invoice":{"id":42}}'): WebhookDelivery
{
    return new WebhookDelivery(
        'tenant:unit',
        $deliveryId,
        $body,
        ['X-Fakturownia-Signature' => 'untrusted-literal'],
    );
}

function s814ForgedSerializedObject(string $class): string
{
    return 'O:'.strlen($class).':"'.$class.'":0:{}';
}

it('bounds and normalizes the untrusted inbound delivery surface', function (): void {
    $id = new ProviderWebhookDeliveryId(str_repeat('d', ProviderWebhookDeliveryId::MaximumBytes));
    $delivery = s814UnitDelivery($id);

    expect((string) $id)->toBe(str_repeat('d', 191))
        ->and(array_keys($delivery->headers()))->toBe(['x-fakturownia-signature'])
        ->and($delivery->rawBody())->toBe('{"invoice":{"id":42}}')
        ->and($delivery->jsonSerialize())->toBe([
            'connection_key' => 'tenant:unit',
            'delivery_id' => '[REDACTED]',
            'payload' => '[REDACTED]',
            'headers' => '[REDACTED]',
        ]);

    expect(fn (): ProviderWebhookDeliveryId => new ProviderWebhookDeliveryId(''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ProviderWebhookDeliveryId => new ProviderWebhookDeliveryId(str_repeat('x', 192)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ProviderWebhookDeliveryId => new ProviderWebhookDeliveryId("unsafe\nvalue"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): WebhookDelivery => s814UnitDelivery(body: ''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): WebhookDelivery => s814UnitDelivery(body: str_repeat('x', WebhookDelivery::MaximumPayloadBytes + 1)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): WebhookDelivery => new WebhookDelivery(
            'tenant:unit',
            null,
            '{}',
            ['X-Signature' => 'one', 'x-signature' => 'two'],
        ))->toThrow(InvalidArgumentException::class);
});

it('encrypts payloads and hashes provider identities without persisting plaintext identifiers', function (): void {
    $protector = new SodiumWebhookPayloadProtector(
        str_repeat("\x11", 32),
        str_repeat("\x13", 32),
        7,
    );
    $delivery = s814UnitDelivery(new ProviderWebhookDeliveryId('provider-delivery-123'));
    $at = new DateTimeImmutable('2026-08-26 10:00:00.123456+00:00');
    $first = $protector->protect($delivery, $at);
    $second = $protector->protect($delivery, $at);

    expect($first->providerDeliveryIdHmac)->toMatch('/^[a-f0-9]{64}$/')
        ->and($first->providerDeliveryIdHmac)->not->toContain('provider-delivery-123')
        ->and($first->payloadHmac)->toBe($second->payloadHmac)
        ->and($first->providerDeliveryIdHmac)->toBe($second->providerDeliveryIdHmac)
        ->and($first->encryptedPayload->ciphertextBase64)->not->toBe($second->encryptedPayload->ciphertextBase64)
        ->and($first->encryptedPayload->ciphertextBase64)->not->toContain($delivery->rawBody())
        ->and($protector->__debugInfo())->toBe([
            'deduplication_master_key' => '[REDACTED]',
            'encryption_master_key' => '[REDACTED]',
            'key_version' => 7,
        ]);
});

it('keeps deduplication identity stable while rotating the independent encryption key', function (): void {
    $delivery = s814UnitDelivery(new ProviderWebhookDeliveryId('provider-delivery-rotation'));
    $receivedAt = new DateTimeImmutable('2026-08-26 10:30:00.000000+00:00');
    $stableDeduplicationKey = str_repeat("\x31", 32);
    $before = (new SodiumWebhookPayloadProtector(
        $stableDeduplicationKey,
        str_repeat("\x32", 32),
        1,
    ))->protect($delivery, $receivedAt);
    $after = (new SodiumWebhookPayloadProtector(
        $stableDeduplicationKey,
        str_repeat("\x33", 32),
        2,
    ))->protect($delivery, $receivedAt);

    expect($before->providerDeliveryIdHmac)->toBe($after->providerDeliveryIdHmac)
        ->and($before->payloadHmac)->toBe($after->payloadHmac)
        ->and($before->encryptedPayload->keyVersion)->toBe(1)
        ->and($after->encryptedPayload->keyVersion)->toBe(2)
        ->and($before->encryptedPayload->ciphertextBase64)
        ->not->toBe($after->encryptedPayload->ciphertextBase64);
});

it('records both verified and unverified deliveries only as non-authoritative hints', function (WebhookSignatureVerification $verification): void {
    $repository = new S814UnitInboxRepository;
    $recorder = new RecordWebhookHintAction(
        new S814UnitSignatureVerifier($verification),
        new SodiumWebhookPayloadProtector(str_repeat("\x16", 32), str_repeat("\x17", 32), 1),
        $repository,
        new S814UnitClock(new DateTimeImmutable('2026-08-26 11:00:00.000000+00:00')),
    );
    $receipt = $recorder->record(s814UnitDelivery(new ProviderWebhookDeliveryId('delivery-42')));

    expect($repository->stores)->toBe(1)
        ->and($repository->entry)->not->toBeNull()
        ->and($receipt->trust)->toBe($verification->trust())
        ->and($receipt->requiresAuthoritativeRead())->toBeTrue()
        ->and($receipt->mayTerminalizeOperation())->toBeFalse();
})->with([
    WebhookSignatureVerification::Unverified,
    WebhookSignatureVerification::Verified,
]);

it('keeps the production receiver fail closed before any inbox or transport work', function (): void {
    $repository = new S814UnitInboxRepository;
    $recorder = new RecordWebhookHintAction(
        new DeferredWebhookSignatureVerifier,
        new SodiumWebhookPayloadProtector(str_repeat("\x18", 32), str_repeat("\x19", 32), 1),
        $repository,
        new S814UnitClock(new DateTimeImmutable('2026-08-26 12:00:00.000000+00:00')),
    );

    expect(fn (): WebhookInboxReceipt => (new ReceiveWebhookAction($recorder))->execute(s814UnitDelivery()))
        ->toThrow(WebhookCapabilityDeferred::class)
        ->and($repository->stores)->toBe(0)
        ->and((new DeferredWebhookSignatureVerifier)->verify(s814UnitDelivery()))
        ->toBe(WebhookSignatureVerification::Unverified);
});

it('rejects native and hostile serialization of intake authority and payload objects', function (): void {
    $deliveryId = new ProviderWebhookDeliveryId('serialization-probe');
    $delivery = s814UnitDelivery($deliveryId);
    $protector = new SodiumWebhookPayloadProtector(str_repeat("\x1a", 32), str_repeat("\x1b", 32), 1);
    $protected = $protector->protect(
        $delivery,
        new DateTimeImmutable('2026-08-26 13:00:00.000000+00:00'),
    );
    $receivedAt = new DateTimeImmutable('2026-08-26 13:00:00.000000+00:00');
    $entry = new WebhookInboxEntry(
        '01K3N000000000000000000814',
        'tenant:unit',
        $protected,
        WebhookSignatureVerification::Unverified,
        $receivedAt,
    );
    $receipt = new WebhookInboxReceipt(
        $entry->id,
        WebhookHintTrust::Untrusted,
        false,
        1,
        $receivedAt,
        $receivedAt,
    );

    foreach ([$deliveryId, $delivery, $protected, $protected->encryptedPayload, $entry, $receipt, $protector, new DeferredWebhookCapabilityGate] as $value) {
        expect(fn (): string => serialize($value))->toThrow(LogicException::class);
        expect(fn (): mixed => unserialize(s814ForgedSerializedObject($value::class)))
            ->toThrow(LogicException::class);
    }

    expect(fn () => clone $delivery)->toThrow(LogicException::class)
        ->and(fn () => clone $protector)->toThrow(LogicException::class)
        ->and(fn () => clone new DeferredWebhookCapabilityGate)->toThrow(LogicException::class);
});

it('rejects an unsafe fallback deduplication window', function (): void {
    expect(fn (): RecordWebhookHintAction => new RecordWebhookHintAction(
        new DeferredWebhookSignatureVerifier,
        new SodiumWebhookPayloadProtector(str_repeat("\x1c", 32), str_repeat("\x1d", 32), 1),
        new S814UnitInboxRepository,
        new S814UnitClock(new DateTimeImmutable('2026-08-26 14:00:00.000000+00:00')),
        0,
    ))->toThrow(InvalidArgumentException::class);
});
