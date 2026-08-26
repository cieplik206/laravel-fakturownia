<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\ReadTransport\Testing;

use Cieplik206\Fakturownia\Client\ReadTransport\ExecutesSealedSaloonReads;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Read\Contracts\ReadRequestExecutor;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Requests\StreamReadRequest;
use Cieplik206\Fakturownia\Read\Responses\JsonReadResponse;
use Cieplik206\Fakturownia\Read\Responses\StreamReadResponse;
use JsonSerializable;
use LogicException;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use SensitiveParameterValue;

/**
 * @internal Executes only against a literal in-memory sender and reserved
 * `.invalid` origins. It is incapable of opening a remote transport.
 */
final readonly class InMemorySaloonReadRequestExecutor implements JsonSerializable, ReadRequestExecutor
{
    use ExecutesSealedSaloonReads;

    public const OriginHost = 'tenant.fakturownia.invalid';

    public const RedirectHost = 'files.fakturownia.invalid';

    private const ApiToken = 'in-memory-contract-token';

    public function __construct(InMemorySaloonSender $sender)
    {
        $baseUrl = BaseUrl::fromString(
            'https://'.self::OriginHost,
            [self::OriginHost, self::RedirectHost],
        );
        $authenticator = new class(self::ApiToken) implements Authenticator
        {
            public function __construct(private readonly string $apiToken) {}

            public function set(PendingRequest $pendingRequest): void
            {
                $pendingRequest->query()->add('api_token', $this->apiToken);
            }
        };
        $connector = new class($sender, $authenticator) extends Connector
        {
            public ?int $tries = 1;

            public bool $allowBaseUrlOverride = false;

            public function __construct(
                InMemorySaloonSender $sender,
                Authenticator $authenticator,
            ) {
                $this->sender = $sender;
                $this->authenticate($authenticator);
            }

            public function resolveBaseUrl(): string
            {
                return 'https://'.InMemorySaloonReadRequestExecutor::OriginHost;
            }

            /** @return array<string, bool|int> */
            protected function defaultConfig(): array
            {
                return [
                    'allow_redirects' => false,
                    'connect_timeout' => 1,
                    'http_errors' => false,
                    'stream' => true,
                    'timeout' => 1,
                    'verify' => true,
                ];
            }

            /** @return never */
            public function __clone()
            {
                throw new LogicException('In-memory read connectors cannot be cloned.');
            }

            /** @return never */
            public function __serialize(): array
            {
                throw new LogicException('In-memory read connectors cannot be serialized.');
            }

            /** @phpstan-param array<array-key, mixed> $data */
            public function __unserialize(array $data): never
            {
                throw new LogicException('In-memory read connectors cannot be unserialized.');
            }
        };

        $this->connector = new SensitiveParameterValue($connector);
        $this->baseUrl = new SensitiveParameterValue($baseUrl);
        $this->sender = new SensitiveParameterValue($sender);
        $this->authenticator = new SensitiveParameterValue($authenticator);
        $this->connectorConfig = $this->validatedConnectorConfig($connector);

        $this->assertConnectorState($connector, $baseUrl->host(), $authenticator);
    }

    public function execute(JsonReadRequest $request): JsonReadResponse
    {
        return $this->executeSealedRead($request);
    }

    public function stream(StreamReadRequest $request): StreamReadResponse
    {
        return $this->streamSealedRead($request);
    }
}
