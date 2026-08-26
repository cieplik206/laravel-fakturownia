<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Resources;

use Cieplik206\Fakturownia\Read\Contracts\ReadCapabilityGate;
use Cieplik206\Fakturownia\Read\Contracts\ReadClock;
use Cieplik206\Fakturownia\Read\Contracts\ReadRequestExecutor;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Requests\StreamReadRequest;
use Cieplik206\Fakturownia\Read\Responses\JsonReadResponse;
use Cieplik206\Fakturownia\Read\Responses\ReadArtifactStream;
use Cieplik206\Fakturownia\Read\Support\ResponseDecoder;
use JsonSerializable;
use LogicException;

abstract readonly class ReadResource implements JsonSerializable
{
    final public function __construct(
        protected ReadRequestExecutor $executor,
        protected ReadCapabilityGate $capabilities,
        protected ResponseDecoder $decoder,
        protected ReadClock $clock,
    ) {}

    final protected function execute(JsonReadRequest $request): JsonReadResponse
    {
        $this->capabilities->assertSupported($request->capability());

        return $this->executor->execute($request);
    }

    final protected function artifact(StreamReadRequest $request): ReadArtifactStream
    {
        $this->capabilities->assertSupported($request->capability());

        return ReadArtifactStream::open($request, $this->executor->stream($request), $this->clock);
    }

    final protected function assertRemotePageSize(
        JsonReadRequest $request,
        JsonReadResponse $response,
        int $itemCount,
        int $perPage,
    ): void {
        if ($itemCount <= $perPage) {
            return;
        }

        throw new ProtocolViolation(
            $request->operation(),
            'bounded page size',
            $response->statusCode,
            $response->headers->providerRequestId(),
        );
    }

    /** @return array{transport: string, credentials: string} */
    final public function __debugInfo(): array
    {
        return ['transport' => 'sealed-read-executor', 'credentials' => '[REDACTED]'];
    }

    /** @return array{transport: string, credentials: string} */
    final public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    final public function __clone()
    {
        throw new LogicException('Credentialed read resources cannot be cloned.');
    }

    /** @return never */
    final public function __serialize(): array
    {
        throw new LogicException('Credentialed read resources cannot be serialized.');
    }

    /** @param array<never, never> $data */
    final public function __unserialize(array $data): never
    {
        throw new LogicException('Credentialed read resources cannot be unserialized.');
    }
}
