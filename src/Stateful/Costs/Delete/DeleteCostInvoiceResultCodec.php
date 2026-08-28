<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Delete;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use InvalidArgumentException;

final readonly class DeleteCostInvoiceResultCodec implements OperationResultCodec
{
    public const string ResultType = 'fakturownia.cost_invoice_deleted';

    public const int SchemaVersion = 1;

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
        if (! $result instanceof DeleteCostInvoiceResult) {
            throw new InvalidArgumentException('Cost invoice delete result codec received an unsupported result.');
        }

        return new EncodedResult(self::ResultType, self::SchemaVersion, ['remote_id' => $result->remoteId]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        if ($result->resultType !== self::ResultType
            || $result->schemaVersion !== self::SchemaVersion
            || array_keys($result->payload) !== ['remote_id']
            || ! is_string($result->payload['remote_id'])) {
            throw new InvalidArgumentException('Cost invoice delete result envelope is invalid.');
        }

        return new DeleteCostInvoiceResult($result->payload['remote_id']);
    }
}
