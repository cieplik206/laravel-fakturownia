<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Administration;

use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class AdministrationReadScope implements JsonSerializable
{
    public const string Provider = 'fakturownia';

    private SensitiveParameterValue $connectionKey;

    private SensitiveParameterValue $operatorReference;

    public function __construct(
        #[SensitiveParameter] ConnectionKey $connectionKey,
        #[SensitiveParameter] AdministrationOperatorReference $operatorReference,
    ) {
        $this->connectionKey = new SensitiveParameterValue($connectionKey);
        $this->operatorReference = new SensitiveParameterValue($operatorReference);
    }

    public function matchesConnection(ConnectionKey $connectionKey): bool
    {
        return $this->connectionKey()->equals($connectionKey);
    }

    public function matchesOperator(AdministrationOperatorReference $operatorReference): bool
    {
        return $this->operatorReference()->equals($operatorReference);
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode("\0", [
            'cieplik206.fakturownia.administration-scope',
            self::Provider,
            $this->connectionKey()->value,
            $this->operatorReference()->fingerprint(),
        ]));
    }

    /** @return array{provider: string, connection: string, operator: string} */
    public function __debugInfo(): array
    {
        return [
            'provider' => self::Provider,
            'connection' => '[REDACTED]',
            'operator' => '[REDACTED]',
        ];
    }

    /** @return array{provider: string, connection: string, operator: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Administration read scopes cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Administration read scopes cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Administration read scopes cannot be unserialized.');
    }

    private function connectionKey(): ConnectionKey
    {
        $connectionKey = $this->connectionKey->getValue();

        if (! $connectionKey instanceof ConnectionKey) {
            throw new LogicException('The administration connection key is corrupted.');
        }

        return $connectionKey;
    }

    private function operatorReference(): AdministrationOperatorReference
    {
        $operatorReference = $this->operatorReference->getValue();

        if (! $operatorReference instanceof AdministrationOperatorReference) {
            throw new LogicException('The administration operator reference is corrupted.');
        }

        return $operatorReference;
    }
}
