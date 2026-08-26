<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Ksef\KsefTerminalOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use InvalidArgumentException;

final readonly class EnsureAcceptedResultCodec implements OperationResultCodec
{
    public const string ResultType = 'fakturownia.invoice.ksef.ensure_accepted.result';

    public const int SchemaVersion = 1;

    /** @var list<string> */
    private const array PayloadKeys = ['remote_id', 'raw_status', 'outcome', 'government_id'];

    public static function resultType(): string
    {
        return self::ResultType;
    }

    public static function schemaVersion(): int
    {
        return self::SchemaVersion;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        if (! $result instanceof EnsureAcceptedResult) {
            throw new InvalidArgumentException('The KSeF result codec received an unsupported result.');
        }

        return new EncodedResult(self::resultType(), self::schemaVersion(), [
            'remote_id' => $result->remoteId,
            'raw_status' => $result->rawStatus,
            'outcome' => $result->outcome->value,
            'government_id' => $result->governmentId,
        ]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        if ($result->resultType !== self::resultType() || $result->schemaVersion !== self::schemaVersion()) {
            throw new InvalidArgumentException('The encoded KSeF result type or schema is unsupported.');
        }

        $keys = array_keys($result->payload);
        sort($keys, SORT_STRING);
        $expected = self::PayloadKeys;
        sort($expected, SORT_STRING);

        if ($keys !== $expected) {
            throw new InvalidArgumentException('The encoded KSeF result contains invalid fields.');
        }

        $remoteId = $result->payload['remote_id'] ?? null;
        $rawStatus = $result->payload['raw_status'] ?? null;
        $outcomeValue = $result->payload['outcome'] ?? null;
        $governmentId = $result->payload['government_id'] ?? null;
        $outcome = is_string($outcomeValue) ? KsefTerminalOutcome::tryFrom($outcomeValue) : null;

        if (! is_string($remoteId)
            || ! is_string($rawStatus)
            || ! $outcome instanceof KsefTerminalOutcome
            || $governmentId !== null && ! is_string($governmentId)) {
            throw new InvalidArgumentException('The encoded KSeF result payload is invalid.');
        }

        return new EnsureAcceptedResult($remoteId, $rawStatus, $outcome, $governmentId);
    }
}
