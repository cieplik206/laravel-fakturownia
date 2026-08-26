<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\DefaultClientFactory;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Client\ValueObjects\SecretValue;
use Cieplik206\Fakturownia\Stateful\ConnectionProfile;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;

it('does not publish a plaintext extraction API', function (): void {
    $secret = SecretValue::fromPlaintext('top-secret-token');
    $client = $secret->createClient(
        BaseUrl::fromString('https://tenant.fakturownia.pl', ['tenant.fakturownia.pl']),
        5,
        20,
    );

    ob_start();
    var_dump($client);
    $clientDebugOutput = (string) ob_get_clean();

    expect($clientDebugOutput)->toContain('[REDACTED]')
        ->not->toContain('top-secret-token')
        ->and((string) json_encode($secret, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token')
        ->and((string) json_encode($client, JSON_THROW_ON_ERROR))->not->toContain('top-secret-token');
});

it('cannot apply credentials to a caller owned pending request through public APIs', function (): void {
    $secret = SecretValue::fromPlaintext('adversarial-secret-token');
    $config = new ConnectionConfig(
        BaseUrl::fromString('https://safe.fakturownia.pl', ['safe.fakturownia.pl']),
        $secret,
    );
    $profile = new ConnectionProfile(
        new ConnectionKey('safe-connection'),
        DeploymentStage::Production,
        $config,
    );
    $attackerConnector = new class extends Connector
    {
        public function resolveBaseUrl(): string
        {
            return 'https://attacker.example';
        }
    };
    $attackerPendingRequest = new PendingRequest($attackerConnector, new class extends Request
    {
        protected Method $method = Method::GET;

        public function resolveEndpoint(): string
        {
            return '/capture';
        }
    });
    $secretMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($secret))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    $configMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($config))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    $profileMethods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($profile))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    $safeClientFromConfig = $config->createClient();
    $safeClientFromProfile = $profile->createClient(new DefaultClientFactory);

    expect(array_merge($secretMethods, $configMethods, $profileMethods))
        ->not->toContain('authenticator')
        ->not->toContain('exposeTo')
        ->and((new ReflectionProperty($profile, 'client'))->isPrivate())->toBeTrue()
        ->and((new ReflectionProperty($config, 'apiToken'))->isPrivate())->toBeTrue()
        ->and($safeClientFromConfig)->not->toBe($safeClientFromProfile)
        ->and($attackerPendingRequest->query()->get('api_token'))->toBeNull();
});

it('redacts debugging and blocks clone and serialization', function (): void {
    $secret = SecretValue::fromPlaintext('top-secret-token');

    ob_start();
    var_dump($secret);
    $debugOutput = (string) ob_get_clean();

    expect($debugOutput)->toContain('[REDACTED]')
        ->not->toContain('top-secret-token')
        ->and(var_export($secret, true))->not->toContain('top-secret-token')
        ->and(fn () => clone $secret)->toThrow(LogicException::class)
        ->and(fn (): string => serialize($secret))->toThrow(LogicException::class);
});

it('rejects empty padded and unexpectedly long credentials', function (string $plaintext): void {
    expect(fn (): SecretValue => SecretValue::fromPlaintext($plaintext))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => '',
    'leading whitespace' => ' token',
    'trailing whitespace' => 'token ',
    'unexpectedly long' => str_repeat('x', 4097),
]);
