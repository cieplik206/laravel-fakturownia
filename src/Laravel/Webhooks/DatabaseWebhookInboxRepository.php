<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Webhooks;

use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookInboxRepository;
use Cieplik206\Fakturownia\Stateful\Webhooks\Exceptions\WebhookDeliveryIdentityCollision;
use Cieplik206\Fakturownia\Stateful\Webhooks\Exceptions\WebhookInboxStorageUnavailable;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookInboxEntry;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookInboxReceipt;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookSignatureVerification;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use stdClass;

final readonly class DatabaseWebhookInboxRepository implements WebhookInboxRepository
{
    private string $table;

    public function __construct(
        private Connection $connection,
        ConfigRepository $config,
    ) {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new WebhookInboxStorageUnavailable(
                'The durable webhook inbox requires an authoritative PostgreSQL connection.',
            );
        }

        $configuredConnection = $config->get('integration-operations.database.connection')
            ?: $config->get('database.default');
        $expectedDatabase = is_string($configuredConnection)
            ? $config->get("database.connections.{$configuredConnection}.database")
            : null;
        $expectedSchema = $config->get('integration-operations.database.schema', 'public');

        if (! is_string($configuredConnection)
            || $configuredConnection === ''
            || ! hash_equals($configuredConnection, $connection->getName())) {
            throw new WebhookInboxStorageUnavailable(
                'The webhook inbox requires the configured authoritative database connection.',
            );
        }

        if (! is_string($expectedDatabase)
            || $expectedDatabase === ''
            || strlen($expectedDatabase) > 63
            || preg_match('//u', $expectedDatabase) !== 1
            || str_contains($expectedDatabase, "\0")) {
            throw new InvalidArgumentException('The expected webhook PostgreSQL database name is invalid.');
        }

        if (! is_string($expectedSchema) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $expectedSchema) !== 1) {
            throw new InvalidArgumentException('The expected webhook PostgreSQL schema name is invalid.');
        }

        $this->expectedDatabase = $expectedDatabase;
        $this->expectedSchema = $expectedSchema;
        $this->table = $expectedSchema.'.fakturownia_webhook_receipts';
        $this->assertDatabaseAuthority();
    }

    private string $expectedDatabase;

    private string $expectedSchema;

    public function store(WebhookInboxEntry $entry, int $fallbackDeduplicationWindowSeconds): WebhookInboxReceipt
    {
        if ($fallbackDeduplicationWindowSeconds < 1 || $fallbackDeduplicationWindowSeconds > 86_400) {
            throw new InvalidArgumentException('The webhook fallback deduplication window must be between 1 second and 24 hours.');
        }

        return $this->connection->transaction(function () use ($entry, $fallbackDeduplicationWindowSeconds): WebhookInboxReceipt {
            $this->assertDatabaseAuthority();

            if ($entry->payload->providerDeliveryIdHmac !== null) {
                return $this->storeProviderIdentified($entry);
            }

            return $this->storePayloadIdentified($entry, $fallbackDeduplicationWindowSeconds);
        });
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Webhook inbox repositories cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Webhook inbox repositories cannot be unserialized.');
    }

    private function storeProviderIdentified(WebhookInboxEntry $entry): WebhookInboxReceipt
    {
        $inserted = $this->connection->table($this->table)->insertOrIgnore($this->row($entry));

        if ($inserted === 1) {
            return $this->receipt($this->requiredRowById($entry->id), false);
        }

        $existing = $this->connection->table($this->table)
            ->where('connection_key', $entry->connectionKey)
            ->where('provider_delivery_id_hmac', $entry->payload->providerDeliveryIdHmac)
            ->useWritePdo()
            ->lockForUpdate()
            ->first();

        if (! $existing instanceof stdClass) {
            throw new RuntimeException('The duplicate webhook delivery could not be loaded after its uniqueness conflict.');
        }

        return $this->recordRedelivery($entry, $existing);
    }

    private function storePayloadIdentified(
        WebhookInboxEntry $entry,
        int $fallbackDeduplicationWindowSeconds,
    ): WebhookInboxReceipt {
        $this->acquirePayloadDeduplicationLock($entry);
        $cutoff = $entry->receivedAt->modify("-{$fallbackDeduplicationWindowSeconds} seconds");
        $existing = $this->connection->table($this->table)
            ->where('connection_key', $entry->connectionKey)
            ->whereNull('provider_delivery_id_hmac')
            ->where('payload_hmac', $entry->payload->payloadHmac)
            ->where('last_received_at', '>=', $this->timestamp($cutoff))
            ->orderByDesc('last_received_at')
            ->orderByDesc('id')
            ->useWritePdo()
            ->lockForUpdate()
            ->first();

        if ($existing instanceof stdClass) {
            return $this->recordRedelivery($entry, $existing);
        }

        $this->connection->table($this->table)->insert($this->row($entry));

        return $this->receipt($this->requiredRowById($entry->id), false);
    }

    private function acquirePayloadDeduplicationLock(WebhookInboxEntry $entry): void
    {
        $lockIdentity = hash(
            'sha256',
            "cieplik206.fakturownia.webhook-fallback.v1\0{$entry->connectionKey}\0{$entry->payload->payloadHmac}",
        );
        $this->connection->selectOne(
            'SELECT pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(?, 0)) AS acquired',
            [$lockIdentity],
            false,
        );
    }

    private function recordRedelivery(WebhookInboxEntry $entry, stdClass $existing): WebhookInboxReceipt
    {
        $id = $this->requiredString($existing, 'id');
        $payloadHmac = $this->requiredString($existing, 'payload_hmac');

        if (! hash_equals($payloadHmac, $entry->payload->payloadHmac)) {
            throw new WebhookDeliveryIdentityCollision(
                'The provider reused one webhook delivery ID for a different payload.',
            );
        }

        $deliveryCount = $this->requiredPositiveInt($existing, 'delivery_count');

        if ($deliveryCount === 2_147_483_647) {
            throw new RuntimeException('The webhook delivery counter is saturated.');
        }

        $existingVerification = WebhookSignatureVerification::tryFrom(
            $this->requiredString($existing, 'signature_status'),
        );

        if ($existingVerification === null) {
            throw new RuntimeException('The stored webhook signature status is invalid.');
        }

        $lastReceivedAt = $this->requiredUtc($existing, 'last_received_at');
        $nextLastReceivedAt = $entry->receivedAt > $lastReceivedAt ? $entry->receivedAt : $lastReceivedAt;
        $nextVerification = $existingVerification === WebhookSignatureVerification::Verified
            ? $existingVerification
            : $entry->signatureVerification;
        $updated = $this->connection->table($this->table)
            ->where('id', $id)
            ->update([
                'signature_status' => $nextVerification->value,
                'delivery_count' => $deliveryCount + 1,
                'last_received_at' => $this->timestamp($nextLastReceivedAt),
            ]);

        if ($updated !== 1) {
            throw new RuntimeException('The webhook redelivery receipt could not be updated atomically.');
        }

        return $this->receipt($this->requiredRowById($id), true);
    }

    /** @return array<string, int|string|null> */
    private function row(WebhookInboxEntry $entry): array
    {
        $encrypted = $entry->payload->encryptedPayload;
        $receivedAt = $this->timestamp($entry->receivedAt);

        return [
            'id' => $entry->id,
            'connection_key' => $entry->connectionKey,
            'provider_delivery_id_hmac' => $entry->payload->providerDeliveryIdHmac,
            'payload_hmac' => $entry->payload->payloadHmac,
            'signature_status' => $entry->signatureVerification->value,
            'payload_key_version' => $encrypted->keyVersion,
            'payload_cipher' => $encrypted->algorithm,
            'payload_nonce' => $encrypted->nonceBase64,
            'payload_ciphertext' => $encrypted->ciphertextBase64,
            'payload_ciphertext_sha256' => $encrypted->ciphertextSha256,
            'delivery_count' => 1,
            'received_at' => $receivedAt,
            'last_received_at' => $receivedAt,
        ];
    }

    private function requiredRowById(string $id): stdClass
    {
        $row = $this->connection->table($this->table)
            ->where('id', $id)
            ->useWritePdo()
            ->first();

        if (! $row instanceof stdClass) {
            throw new RuntimeException('The webhook receipt could not be loaded from the authoritative database.');
        }

        return $row;
    }

    private function receipt(stdClass $row, bool $duplicate): WebhookInboxReceipt
    {
        $verification = WebhookSignatureVerification::tryFrom($this->requiredString($row, 'signature_status'));

        if ($verification === null) {
            throw new RuntimeException('The stored webhook signature status is invalid.');
        }

        return new WebhookInboxReceipt(
            $this->requiredString($row, 'id'),
            $verification->trust(),
            $duplicate,
            $this->requiredPositiveInt($row, 'delivery_count'),
            $this->requiredUtc($row, 'received_at'),
            $this->requiredUtc($row, 'last_received_at'),
        );
    }

    private function requiredString(stdClass $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The webhook receipt has an invalid {$field} value.");
        }

        return $value;
    }

    private function requiredPositiveInt(stdClass $row, string $field): int
    {
        $value = $row->{$field} ?? null;

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("The webhook receipt has an invalid {$field} value.");
        }

        return $value;
    }

    private function requiredUtc(stdClass $row, string $field): DateTimeImmutable
    {
        $value = $row->{$field} ?? null;

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The webhook receipt has an invalid {$field} value.");
        }

        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        if ($value->getOffset() !== 0) {
            throw new InvalidArgumentException('Webhook database timestamps must use UTC.');
        }

        return $value->format('Y-m-d H:i:s.uP');
    }

    private function assertDatabaseAuthority(): void
    {
        $authority = $this->connection->selectOne(
            'SELECT current_database() AS database_name, current_schema() AS schema_name',
            [],
            false,
        );

        if (! $authority instanceof stdClass
            || ! is_string($authority->database_name ?? null)
            || ! is_string($authority->schema_name ?? null)
            || ! hash_equals($this->expectedDatabase, $authority->database_name)
            || ! hash_equals($this->expectedSchema, $authority->schema_name)) {
            throw new WebhookInboxStorageUnavailable(
                'The webhook inbox connection does not match its pinned PostgreSQL database and schema.',
            );
        }
    }
}
