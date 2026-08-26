<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Diagnostics;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use InvalidArgumentException;

final readonly class FakturowniaDiagnosticResult implements OperationResult
{
    use RejectsNativeSerialization;

    public function __construct(public string $challenge)
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $challenge) !== 1) {
            throw new InvalidArgumentException('Diagnostic challenge is invalid.');
        }
    }

    public function resultType(): string
    {
        return FakturowniaDiagnosticProviderExtensions::resultType();
    }
}
