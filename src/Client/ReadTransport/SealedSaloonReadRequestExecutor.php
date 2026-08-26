<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\ReadTransport;

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
use SensitiveParameter;
use SensitiveParameterValue;

/** @internal */
final readonly class SealedSaloonReadRequestExecutor implements JsonSerializable, ReadRequestExecutor
{
    use ExecutesSealedSaloonReads;

    public function __construct(
        #[SensitiveParameter] Connector $connector,
        #[SensitiveParameter] BaseUrl $baseUrl,
    ) {
        $sender = $connector->sender();
        $authenticator = $connector->getAuthenticator();

        if (! $authenticator instanceof Authenticator) {
            throw new LogicException('The credentialed read connector has no sealed authenticator.');
        }

        $this->connector = new SensitiveParameterValue($connector);
        $this->baseUrl = new SensitiveParameterValue($baseUrl);
        $this->sender = new SensitiveParameterValue($sender);
        $this->authenticator = new SensitiveParameterValue($authenticator);
        $this->connectorConfig = $this->validatedConnectorConfig($connector);

        $this->assertConnectorState($connector, $baseUrl->host(), $authenticator);
    }

    public function execute(JsonReadRequest $request): JsonReadResponse
    {
        $this->assertJsonRequestContract($request);
        (new PinnedReadCapabilityGate)->assertSupported($request->capability());

        return $this->executeSealedRead($request);
    }

    public function stream(StreamReadRequest $request): StreamReadResponse
    {
        $this->assertStreamRequestContract($request);
        (new PinnedReadCapabilityGate)->assertSupported($request->capability());

        return $this->streamSealedRead($request);
    }
}
