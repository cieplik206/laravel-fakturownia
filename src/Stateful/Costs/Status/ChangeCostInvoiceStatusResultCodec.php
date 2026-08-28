<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Costs\Status;

use Cieplik206\Fakturownia\Read\Data\OpenInvoiceStatus;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use InvalidArgumentException;

final readonly class ChangeCostInvoiceStatusResultCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return 'fakturownia.cost_invoice_status_changed';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        if (! $result instanceof ChangeCostInvoiceStatusResult) {
            throw new InvalidArgumentException('Cost invoice status result codec received an unsupported result.');
        }

        return new EncodedResult(
            self::resultType(),
            self::schemaVersion(),
            ['remote_id' => $result->remoteId, 'status' => $result->status->raw],
        );
    }

    public function decode(EncodedResult $result): OperationResult
    {
        if ($result->resultType !== self::resultType()
            || $result->schemaVersion !== self::schemaVersion()
            || array_keys($result->payload) !== ['remote_id', 'status']
            || ! is_string($result->payload['remote_id'])
            || ! is_string($result->payload['status'])) {
            throw new InvalidArgumentException('Cost invoice status result envelope is invalid.');
        }

        return new ChangeCostInvoiceStatusResult(
            $result->payload['remote_id'],
            new OpenInvoiceStatus($result->payload['status']),
        );
    }
}
