<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\DefaultClientFactory;
use Cieplik206\Fakturownia\Client\FakturowniaClient;
use Cieplik206\Fakturownia\Client\ReadTransport\SealedSaloonReadRequestExecutor;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Client\ValueObjects\SecretValue;
use Cieplik206\Fakturownia\Read\FakturowniaReadClient;
use Cieplik206\Fakturownia\Read\Resources\ReadResource;
use Cieplik206\Fakturownia\Read\Retry\NativeReadSleeper;
use Cieplik206\Fakturownia\Read\Retry\ReadRetryPolicy;
use Cieplik206\Fakturownia\Read\Retry\RetryingReadRequestExecutor;
use Cieplik206\Fakturownia\Read\Retry\SecureReadJitter;
use Cieplik206\Fakturownia\Read\Retry\SystemReadClock;
use Saloon\Contracts\Authenticator;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;

function secureClientFixture(): FakturowniaClient
{
    return (new DefaultClientFactory)->make(new ConnectionConfig(
        BaseUrl::fromString('https://tenant.fakturownia.pl', ['tenant.fakturownia.pl']),
        SecretValue::fromPlaintext('isolated-token'),
        3,
        8,
    ));
}

function connectorFromSecureClient(FakturowniaClient $client): Connector
{
    $protectedConnector = (new ReflectionProperty($client, 'connector'))->getValue($client);

    if (! $protectedConnector instanceof SensitiveParameterValue) {
        throw new RuntimeException('The test-only transport seam did not resolve protected connector storage.');
    }

    $connector = $protectedConnector->getValue();

    if (! $connector instanceof Connector) {
        throw new RuntimeException('The test-only transport seam did not resolve a connector.');
    }

    return $connector;
}

function readExecutorFromSecureClient(FakturowniaClient $client): RetryingReadRequestExecutor
{
    $protectedReadClient = (new ReflectionProperty($client, 'readClient'))->getValue($client);

    if (! $protectedReadClient instanceof SensitiveParameterValue) {
        throw new RuntimeException('The test-only read seam did not resolve protected client storage.');
    }

    $readClient = $protectedReadClient->getValue();

    if (! $readClient instanceof FakturowniaReadClient) {
        throw new RuntimeException('The test-only read seam did not resolve a read client.');
    }

    $executor = (new ReflectionProperty(ReadResource::class, 'executor'))->getValue($readClient->invoices());

    if (! $executor instanceof RetryingReadRequestExecutor) {
        throw new RuntimeException('The production read client is not wrapped by the retry executor.');
    }

    return $executor;
}

it('disables redirects base URL overrides and transport retries', function (): void {
    $client = secureClientFixture();
    $connector = connectorFromSecureClient($client);
    $clientReflection = new ReflectionClass($client);

    expect($connector->config()->get('allow_redirects'))->toBeFalse()
        ->and($connector->config()->get('verify'))->toBeTrue()
        ->and($connector->config()->get('connect_timeout'))->toBe(3)
        ->and($connector->config()->get('timeout'))->toBe(8)
        ->and($connector->allowBaseUrlOverride)->toBeFalse()
        ->and($connector->tries)->toBe(1)
        ->and($clientReflection->getMethod('connector')->isPrivate())->toBeTrue()
        ->and($clientReflection->getProperty('connector')->isPrivate())->toBeTrue();
});

it('adds the token only through a redacted authenticator', function (): void {
    $connector = connectorFromSecureClient(secureClientFixture());
    $authenticator = $connector->getAuthenticator();

    expect($authenticator)->toBeInstanceOf(Authenticator::class);

    ob_start();
    var_dump($authenticator);
    $debugOutput = (string) ob_get_clean();

    expect($debugOutput)->toContain('[REDACTED]')
        ->not->toContain('isolated-token');

    $pendingRequest = new PendingRequest($connector, new class extends Request
    {
        protected Method $method = Method::GET;

        public function resolveEndpoint(): string
        {
            return '/account.json';
        }
    });

    expect($pendingRequest->query()->get('api_token'))->toBe('isolated-token');
});

it('wires the exact bounded retry chain around the sealed production executor', function (): void {
    $retrying = readExecutorFromSecureClient(secureClientFixture());
    $reflection = new ReflectionClass($retrying);
    $executor = $reflection->getProperty('executor')->getValue($retrying);
    $policy = $reflection->getProperty('policy')->getValue($retrying);
    $sleeper = $reflection->getProperty('sleeper')->getValue($retrying);
    $jitter = $reflection->getProperty('jitter')->getValue($retrying);
    $clock = $reflection->getProperty('clock')->getValue($retrying);

    expect($executor)->toBeInstanceOf(SealedSaloonReadRequestExecutor::class)
        ->and($policy)->toBeInstanceOf(ReadRetryPolicy::class)
        ->and($policy->maximumAttempts)->toBe(4)
        ->and($policy->maximumDelayMilliseconds)->toBe(8_000)
        ->and($policy->maximumTotalDelayMilliseconds)->toBe(30_000)
        ->and($sleeper)->toBeInstanceOf(NativeReadSleeper::class)
        ->and($jitter)->toBeInstanceOf(SecureReadJitter::class)
        ->and($clock)->toBeInstanceOf(SystemReadClock::class);
});
