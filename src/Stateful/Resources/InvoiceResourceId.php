<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Resources;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use InvalidArgumentException;
use Stringable;
use Symfony\Component\Uid\Ulid;

final readonly class InvoiceResourceId implements Stringable
{
    use RejectsNativeSerialization;

    public string $value;

    public function __construct(string $value)
    {
        if (! Ulid::isValid($value)) {
            throw new InvalidArgumentException('Invoice resource ID must be a valid ULID.');
        }

        $this->value = (string) Ulid::fromString($value);
    }

    public static function fromOperationId(OperationId $operationId): self
    {
        return new self($operationId->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
