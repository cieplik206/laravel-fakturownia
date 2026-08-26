<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Ksef;

use Cieplik206\Fakturownia\Stateful\Ksef\InvoiceKsefState;
use Cieplik206\Fakturownia\Stateful\Ksef\KsefStatusCategory;
use Cieplik206\Fakturownia\Stateful\Ksef\OpenKsefStatus;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefStateProjectionStore;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Contracts\KsefStateReader;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedObservationProjectionPlanner;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Ksef\Operations\Exceptions\KsefStateProjectionConflict;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionMutation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use LogicException;
use stdClass;

final readonly class DatabaseKsefStateProjectionStore implements KsefStateProjectionStore, KsefStateReader
{
    /** @var list<string> */
    private const array ValueKeys = [
        'remote_id',
        'raw_status',
        'status_category',
        'government_id',
        'provider_error_count',
        'offline',
        'configuration_blocked',
        'overdue',
        'operation_id',
        'connection_key',
        'resource_id',
        'observation_fingerprint',
    ];

    public function __construct(
        private KernelDatabase $database,
    ) {}

    public function apply(OperationView $operation, ObservationProjectionPlan $plan): void
    {
        if ($plan->mutations === []) {
            return;
        }

        $connection = $this->database->connection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('KSeF state must be projected inside the kernel transaction.');
        }

        $command = (new EnsureAcceptedPayloadCodec)->decode($operation->payload());

        if ($operation->scope()->provider->value !== 'fakturownia'
            || $operation->operationType()->value !== EnsureAcceptedOperationDefinitionProvider::OperationType
            || ! $command->connectionKey->equals($operation->scope()->connection)
            || $plan->schemaVersion !== EnsureAcceptedObservationProjectionPlanner::SchemaVersion
            || count($plan->mutations) !== 2) {
            throw new KsefStateProjectionConflict('The KSeF projection contract does not match the operation.');
        }

        $state = $this->mutation($plan, EnsureAcceptedObservationProjectionPlanner::StateTargetId);
        $history = $this->mutation($plan, EnsureAcceptedObservationProjectionPlanner::HistoryTargetId);
        $values = $this->validatedValues($state, $operation, $command->resourceId->value, $command->remoteId);
        $this->assertHistoryIdentity($history, $values);
        $observedAt = $this->databaseNow($connection);

        $this->persistHistory($connection, $history, $values, $observedAt);
        $this->persistState($connection, $values, $observedAt);
    }

    public function find(ConnectionKey $connectionKey, InvoiceResourceId $resourceId): ?InvoiceKsefState
    {
        $row = $this->database->connection()
            ->table('fakturownia_invoice_ksef_states')
            ->where('connection_key', $connectionKey->value)
            ->where('resource_id', $resourceId->value)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByOperation(ConnectionKey $connectionKey, OperationId $operationId): ?InvoiceKsefState
    {
        $row = $this->database->connection()
            ->table('fakturownia_invoice_ksef_states')
            ->where('connection_key', $connectionKey->value)
            ->where('last_operation_id', $operationId->value)
            ->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    private function mutation(ObservationProjectionPlan $plan, string $targetId): ProjectionMutation
    {
        foreach ($plan->mutations as $mutation) {
            if ($mutation->targetId === $targetId) {
                return $mutation;
            }
        }

        throw new KsefStateProjectionConflict("The KSeF projection target {$targetId} is missing.");
    }

    /**
     * @return array<string, null|bool|int|string>
     */
    private function validatedValues(
        ProjectionMutation $mutation,
        OperationView $operation,
        string $resourceId,
        string $remoteId,
    ): array {
        $keys = array_keys($mutation->values);
        sort($keys, SORT_STRING);
        $expected = self::ValueKeys;
        sort($expected, SORT_STRING);
        $values = $mutation->values;

        if ($keys !== $expected
            || $mutation->identity !== ['resource_id' => $resourceId]
            || ($values['resource_id'] ?? null) !== $resourceId
            || ($values['operation_id'] ?? null) !== $operation->operationId()->value
            || ($values['connection_key'] ?? null) !== $operation->scope()->connection->value
            || ($values['remote_id'] ?? null) !== $remoteId
            || ! is_string($values['raw_status'] ?? null)
            || ! is_string($values['status_category'] ?? null)
            || KsefStatusCategory::tryFrom($values['status_category']) === null
            || ! is_int($values['provider_error_count'] ?? null)
            || ! is_bool($values['offline'] ?? null)
            || ! is_bool($values['configuration_blocked'] ?? null)
            || ! is_bool($values['overdue'] ?? null)
            || ! is_string($values['observation_fingerprint'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $values['observation_fingerprint']) !== 1
            || (($values['government_id'] ?? null) !== null && ! is_string($values['government_id']))) {
            throw new KsefStateProjectionConflict('The KSeF state projection values are invalid.');
        }

        return $values;
    }

    /** @param array<string, null|bool|int|string> $values */
    private function assertHistoryIdentity(ProjectionMutation $history, array $values): void
    {
        if ($history->values !== $values
            || $history->identity !== [
                'operation_id' => $values['operation_id'],
                'observation_fingerprint' => $values['observation_fingerprint'],
            ]) {
            throw new KsefStateProjectionConflict('The KSeF history projection does not match the current state mutation.');
        }
    }

    /** @param array<string, null|bool|int|string> $values */
    private function persistHistory(
        Connection $connection,
        ProjectionMutation $history,
        array $values,
        string $observedAt,
    ): void {
        $historyId = hash(
            'sha256',
            $values['operation_id']."\0".$values['observation_fingerprint'],
        );
        $row = [
            'id' => $historyId,
            'operation_id' => $values['operation_id'],
            'resource_id' => $values['resource_id'],
            'connection_key' => $values['connection_key'],
            'remote_id' => $values['remote_id'],
            'raw_status' => $values['raw_status'],
            'status_category' => $values['status_category'],
            'government_id' => $values['government_id'],
            'provider_error_count' => $values['provider_error_count'],
            'offline' => $values['offline'],
            'configuration_blocked' => $values['configuration_blocked'],
            'overdue' => $values['overdue'],
            'observation_fingerprint' => $values['observation_fingerprint'],
            'observed_at' => $observedAt,
        ];
        $inserted = $connection->table('fakturownia_invoice_ksef_state_history')->insertOrIgnore($row);

        if ($inserted === 1) {
            return;
        }

        $existing = $connection->table('fakturownia_invoice_ksef_state_history')
            ->where('operation_id', $history->identity['operation_id'])
            ->where('observation_fingerprint', $history->identity['observation_fingerprint'])
            ->first();

        if (! $existing instanceof stdClass || ! $this->historyMatches($existing, $row)) {
            throw new KsefStateProjectionConflict('The durable KSeF observation history conflicts with its replay.');
        }
    }

    /** @param array<string, null|bool|int|string> $values */
    private function persistState(Connection $connection, array $values, string $observedAt): void
    {
        $existing = $connection->table('fakturownia_invoice_ksef_states')
            ->where('resource_id', $values['resource_id'])
            ->lockForUpdate()
            ->first();
        $category = KsefStatusCategory::from((string) $values['status_category']);

        if (! $existing instanceof stdClass) {
            $connection->table('fakturownia_invoice_ksef_states')->insert([
                ...$this->stateValues($values),
                'row_version' => 1,
                'created_at' => $observedAt,
                'observed_at' => $observedAt,
                'accepted_at' => $category === KsefStatusCategory::Succeeded ? $observedAt : null,
                'rejected_at' => $category->isTerminal() ? $observedAt : null,
                'overdue_at' => $values['overdue'] === true ? $observedAt : null,
                'updated_at' => $observedAt,
            ]);

            return;
        }

        $this->assertExistingStateIdentity($existing, $values);

        if (($existing->observation_fingerprint ?? null) === $values['observation_fingerprint']
            && ($existing->last_operation_id ?? null) === $values['operation_id']) {
            return;
        }

        if (($existing->status_category ?? null) === KsefStatusCategory::Succeeded->value
            && ($values['status_category'] !== KsefStatusCategory::Succeeded->value
                || ($existing->government_id ?? null) !== $values['government_id'])) {
            throw new KsefStateProjectionConflict('An accepted KSeF state cannot regress or change government ID.');
        }

        if (! is_int($existing->row_version ?? null)) {
            throw new KsefStateProjectionConflict('The durable KSeF state row version is invalid.');
        }

        $updated = $connection->table('fakturownia_invoice_ksef_states')
            ->where('resource_id', $values['resource_id'])
            ->where('row_version', $existing->row_version)
            ->update([
                ...$this->stateValues($values),
                'row_version' => $existing->row_version + 1,
                'observed_at' => $observedAt,
                'accepted_at' => $category === KsefStatusCategory::Succeeded
                    ? ($existing->accepted_at ?? $observedAt)
                    : $existing->accepted_at,
                'rejected_at' => $category->isTerminal()
                    ? ($existing->rejected_at ?? $observedAt)
                    : $existing->rejected_at,
                'overdue_at' => $values['overdue'] === true
                    ? ($existing->overdue_at ?? $observedAt)
                    : $existing->overdue_at,
                'updated_at' => $observedAt,
            ]);

        if ($updated !== 1) {
            throw new KsefStateProjectionConflict('The durable KSeF state changed concurrently.');
        }
    }

    /**
     * @param  array<string, null|bool|int|string>  $values
     * @return array<string, null|bool|int|string>
     */
    private function stateValues(array $values): array
    {
        return [
            'resource_id' => $values['resource_id'],
            'connection_key' => $values['connection_key'],
            'remote_id' => $values['remote_id'],
            'last_operation_id' => $values['operation_id'],
            'raw_status' => $values['raw_status'],
            'status_category' => $values['status_category'],
            'government_id' => $values['government_id'],
            'provider_error_count' => $values['provider_error_count'],
            'offline' => $values['offline'],
            'configuration_blocked' => $values['configuration_blocked'],
            'overdue' => $values['overdue'],
            'observation_fingerprint' => $values['observation_fingerprint'],
        ];
    }

    /** @param array<string, null|bool|int|string> $values */
    private function assertExistingStateIdentity(stdClass $existing, array $values): void
    {
        if (($existing->connection_key ?? null) !== $values['connection_key']
            || ($existing->remote_id ?? null) !== $values['remote_id']) {
            throw new KsefStateProjectionConflict('The KSeF state resource identity is immutable.');
        }
    }

    /** @param array<string, mixed> $row */
    private function historyMatches(stdClass $existing, array $row): bool
    {
        foreach ($row as $key => $value) {
            if ($key === 'observed_at') {
                continue;
            }

            if (($existing->{$key} ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function databaseNow(Connection $connection): string
    {
        $row = $connection->selectOne('SELECT clock_timestamp() AS observed_at');

        if (! $row instanceof stdClass || ! is_string($row->observed_at ?? null)) {
            throw new KsefStateProjectionConflict('The KSeF projection database clock is unavailable.');
        }

        return $row->observed_at;
    }

    private function hydrate(stdClass $row): InvoiceKsefState
    {
        if (! is_string($row->resource_id ?? null)
            || ! is_string($row->connection_key ?? null)
            || ! is_string($row->remote_id ?? null)
            || ! is_string($row->last_operation_id ?? null)
            || ! is_string($row->raw_status ?? null)
            || ! is_string($row->observation_fingerprint ?? null)
            || ! is_int($row->provider_error_count ?? null)
            || ! is_bool($row->offline ?? null)
            || ! is_bool($row->configuration_blocked ?? null)
            || ! is_bool($row->overdue ?? null)
            || ! is_int($row->row_version ?? null)
            || ! is_string($row->observed_at ?? null)
            || (($row->government_id ?? null) !== null && ! is_string($row->government_id))) {
            throw new KsefStateProjectionConflict('The durable KSeF state cannot be hydrated.');
        }

        return new InvoiceKsefState(
            resourceId: new InvoiceResourceId($row->resource_id),
            connectionKey: new ConnectionKey($row->connection_key),
            remoteId: $row->remote_id,
            lastOperationId: new OperationId($row->last_operation_id),
            status: new OpenKsefStatus($row->raw_status),
            governmentId: $row->government_id,
            providerErrorCount: $row->provider_error_count,
            offline: $row->offline,
            configurationBlocked: $row->configuration_blocked,
            overdue: $row->overdue,
            observationFingerprint: $row->observation_fingerprint,
            rowVersion: $row->row_version,
            observedAt: $this->utc($row->observed_at),
            acceptedAt: $this->nullableUtc($row->accepted_at ?? null),
            rejectedAt: $this->nullableUtc($row->rejected_at ?? null),
            overdueAt: $this->nullableUtc($row->overdue_at ?? null),
        );
    }

    private function nullableUtc(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new KsefStateProjectionConflict('The durable KSeF state timestamp is invalid.');
        }

        return $this->utc($value);
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}
