<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionMutation;
use InvalidArgumentException;
use JsonException;

final readonly class EnsureAcceptedObservationProjectionPlanner implements ObservationProjectionPlanner
{
    public const int SchemaVersion = 1;

    public const string StateTargetId = 'fakturownia.invoice_ksef_state';

    public const string HistoryTargetId = 'fakturownia.invoice_ksef_history';

    /** @var list<string> */
    private const array ObservationKeys = [
        'remote_id',
        'raw_status',
        'status_category',
        'government_id',
        'provider_error_count',
        'offline',
        'configuration_blocked',
        'overdue',
    ];

    public function plan(ObservationProjectionInput $input): ObservationProjectionPlan
    {
        $command = (new EnsureAcceptedPayloadCodec)->decode($input->operation->payload());
        $observation = $input->observation->providerObservation;

        if ($input->operation->scope()->provider->value !== 'fakturownia'
            || $input->operation->operationType()->value !== EnsureAcceptedOperationDefinitionProvider::OperationType
            || ! $command->connectionKey->equals($input->operation->scope()->connection)) {
            throw new InvalidArgumentException('The KSeF observation projection scope is invalid.');
        }

        if (! $observation instanceof CanonicalObject) {
            return new ObservationProjectionPlan(self::SchemaVersion, []);
        }

        return new ObservationProjectionPlan(
            self::SchemaVersion,
            $this->mutations($input->operation->operationId()->value, $command, $observation->values),
        );
    }

    /**
     * @param  array<string, mixed>  $observation
     * @return list<ProjectionMutation>
     */
    public function mutations(string $operationId, EnsureAcceptedCommand $command, array $observation): array
    {
        $keys = array_keys($observation);
        sort($keys, SORT_STRING);
        $expected = self::ObservationKeys;
        sort($expected, SORT_STRING);

        if ($keys !== $expected || ($observation['remote_id'] ?? null) !== $command->remoteId) {
            throw new InvalidArgumentException('The KSeF provider observation is not canonical for the command.');
        }

        $values = $this->scalarValues($observation);
        $fingerprint = $this->fingerprint($values);
        $values = [
            ...$values,
            'operation_id' => $operationId,
            'connection_key' => $command->connectionKey->value,
            'resource_id' => $command->resourceId->value,
            'observation_fingerprint' => $fingerprint,
        ];

        return [
            new ProjectionMutation(
                self::StateTargetId,
                ['resource_id' => $command->resourceId->value],
                null,
                $values,
            ),
            new ProjectionMutation(
                self::HistoryTargetId,
                [
                    'operation_id' => $operationId,
                    'observation_fingerprint' => $fingerprint,
                ],
                null,
                $values,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, null|bool|int|string>
     */
    private function scalarValues(array $values): array
    {
        foreach ($values as $value) {
            if (! (is_null($value) || is_bool($value) || is_int($value) || is_string($value))) {
                throw new InvalidArgumentException('The KSeF observation contains a non-scalar projection value.');
            }
        }

        /** @var array<string, null|bool|int|string> $values */
        return $values;
    }

    /** @param array<string, null|bool|int|string> $values */
    private function fingerprint(array $values): string
    {
        try {
            return hash('sha256', (new CanonicalJsonV1)->encode(new CanonicalObject($values)));
        } catch (JsonException) {
            throw new InvalidArgumentException('The KSeF observation fingerprint cannot be computed.');
        }
    }
}
