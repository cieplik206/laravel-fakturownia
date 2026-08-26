<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Contracts\ReadRequestDescriptor;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadSafety;
use InvalidArgumentException;
use JsonException;

abstract readonly class JsonReadRequest implements ReadRequestDescriptor
{
    protected function __construct(
        private string $operationName,
        private ReadCapability $requiredCapability,
        private string $endpointPath,
        private QueryParameters $queryParameters,
        private int $responseByteLimit = 8_388_608,
        private ReadSafety $retrySafety = ReadSafety::Safe,
    ) {
        self::assertCommonContract($operationName, $requiredCapability, $endpointPath, $responseByteLimit);
    }

    final public function operation(): string
    {
        return $this->operationName;
    }

    final public function capability(): ReadCapability
    {
        return $this->requiredCapability;
    }

    final public function path(): string
    {
        return $this->endpointPath;
    }

    final public function query(): QueryParameters
    {
        return $this->queryParameters;
    }

    final public function safety(): ReadSafety
    {
        return $this->retrySafety;
    }

    final public function maximumResponseBytes(): int
    {
        return $this->responseByteLimit;
    }

    /** @throws JsonException */
    final public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'class' => static::class,
            'operation' => $this->operationName,
            'capability' => $this->requiredCapability->value,
            'path' => $this->endpointPath,
            'query' => $this->queryParameters->all(),
            'maximum_response_bytes' => $this->responseByteLimit,
            'retry_safety' => $this->retrySafety->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    final protected static function assertCommonContract(
        string $operationName,
        ReadCapability $requiredCapability,
        string $endpointPath,
        int $responseByteLimit,
    ): void {
        if ($operationName !== $requiredCapability->value) {
            throw new InvalidArgumentException('The request operation must match its capability.');
        }

        if (preg_match('/^\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]+$/', $endpointPath) !== 1
            || str_contains($endpointPath, '//')
            || str_contains($endpointPath, '..')) {
            throw new InvalidArgumentException('The read endpoint path is invalid.');
        }

        if ($responseByteLimit < 1 || $responseByteLimit > 16_777_216) {
            throw new InvalidArgumentException('The JSON response byte limit is invalid.');
        }
    }
}
