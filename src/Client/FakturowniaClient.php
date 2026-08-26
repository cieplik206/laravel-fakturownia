<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client;

use Cieplik206\Fakturownia\Client\ReadTransport\PinnedReadCapabilityGate;
use Cieplik206\Fakturownia\Client\ReadTransport\SealedSaloonReadRequestExecutor;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Read\FakturowniaReadClient;
use Cieplik206\Fakturownia\Read\Retry\NativeReadSleeper;
use Cieplik206\Fakturownia\Read\Retry\ReadRetryPolicy;
use Cieplik206\Fakturownia\Read\Retry\RetryingReadRequestExecutor;
use Cieplik206\Fakturownia\Read\Retry\SecureReadJitter;
use Cieplik206\Fakturownia\Read\Retry\SystemReadClock;
use JsonSerializable;
use LogicException;
use Saloon\Http\Connector;
use Saloon\Http\Senders\GuzzleSender;
use SensitiveParameter;
use SensitiveParameterValue;

/** @internal */
final readonly class FakturowniaClient implements JsonSerializable
{
    private SensitiveParameterValue $connector;

    private SensitiveParameterValue $readClient;

    public function __construct(
        #[SensitiveParameter] Connector $connector,
        #[SensitiveParameter] BaseUrl $baseUrl,
    ) {
        if ($connector->sender()::class !== GuzzleSender::class) {
            throw new LogicException('The production client requires the exact sealed Saloon sender.');
        }

        $this->connector = new SensitiveParameterValue($connector);
        $this->readClient = new SensitiveParameterValue(new FakturowniaReadClient(
            new RetryingReadRequestExecutor(
                new SealedSaloonReadRequestExecutor($connector, $baseUrl),
                new ReadRetryPolicy,
                new NativeReadSleeper,
                new SecureReadJitter,
                new SystemReadClock,
            ),
            new PinnedReadCapabilityGate,
        ));
    }

    public function read(): FakturowniaReadClient
    {
        $readClient = $this->readClient->getValue();

        if (! $readClient instanceof FakturowniaReadClient) {
            throw new LogicException('The credentialed read client is corrupted.');
        }

        return $readClient;
    }

    /** @return array{transport: string, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'transport' => $this->connector()->allowBaseUrlOverride ? 'unsafe' : 'isolated',
            'credentials' => '[REDACTED]',
        ];
    }

    /** @return array{transport: string, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Credentialed clients cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Credentialed clients cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Credentialed clients cannot be unserialized.');
    }

    private function connector(): Connector
    {
        $connector = $this->connector->getValue();

        if (! $connector instanceof Connector) {
            throw new LogicException('The credentialed connector is corrupted.');
        }

        return $connector;
    }
}
