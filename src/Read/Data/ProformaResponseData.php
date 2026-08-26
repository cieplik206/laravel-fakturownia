<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;

final readonly class ProformaResponseData
{
    use RejectsNativeSerialization;

    private function __construct(public InvoiceResponseData $snapshot) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, string $operation): self
    {
        $snapshot = InvoiceResponseData::fromPayload($payload, $operation);
        $kind = $snapshot->kind;

        if ($kind === null || ! hash_equals(KnownInvoiceKind::Proforma->value, $kind->raw)) {
            throw new ProtocolViolation($operation, 'exact proforma kind field');
        }

        return new self($snapshot);
    }

    /** @return array{remote_id: string, kind: string, status: ?string, pii: string, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'remote_id' => $this->snapshot->remoteId,
            'kind' => KnownInvoiceKind::Proforma->value,
            'status' => $this->snapshot->status?->raw,
            'pii' => '[REDACTED]',
            'credentials' => '[NOT_PRESENT]',
        ];
    }
}
