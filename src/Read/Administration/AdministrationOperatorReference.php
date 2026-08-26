<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Administration;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class AdministrationOperatorReference implements JsonSerializable
{
    private SensitiveParameterValue $value;

    public function __construct(#[SensitiveParameter] string $value)
    {
        if (preg_match('/^[a-z][a-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException('The administration operator reference is invalid.');
        }

        $this->value = new SensitiveParameterValue($value);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->plainValue(), $other->plainValue());
    }

    public function fingerprint(): string
    {
        return hash('sha256', "cieplik206.fakturownia.administration-operator\0".$this->plainValue());
    }

    /** @return array{operator_reference: string} */
    public function __debugInfo(): array
    {
        return ['operator_reference' => '[REDACTED]'];
    }

    /** @return array{operator_reference: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Administration operator references cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Administration operator references cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Administration operator references cannot be unserialized.');
    }

    private function plainValue(): string
    {
        $value = $this->value->getValue();

        if (! is_string($value)) {
            throw new LogicException('The administration operator reference is corrupted.');
        }

        return $value;
    }
}
