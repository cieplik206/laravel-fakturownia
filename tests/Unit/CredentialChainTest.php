<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Client\ConnectionConfig;
use Cieplik206\Fakturownia\Client\DefaultClientFactory;
use Cieplik206\Fakturownia\Client\FakturowniaClient;
use Cieplik206\Fakturownia\Client\ValueObjects\BaseUrl;
use Cieplik206\Fakturownia\Client\ValueObjects\SecretValue;
use Cieplik206\Fakturownia\Laravel\ConfigConnectionResolver;
use Cieplik206\Fakturownia\Stateful\ConnectionProfile;
use Cieplik206\Fakturownia\Stateful\DeploymentStage;
use Cieplik206\Fakturownia\Stateful\FakturowniaConnection;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Illuminate\Config\Repository;
use Saloon\Http\Connector;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

function credentialChainConnector(FakturowniaClient $client): Connector
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

it('redacts and blocks copying of the complete credential object chain', function (): void {
    $secret = SecretValue::fromPlaintext('chain-secret-token');
    $config = new ConnectionConfig(
        BaseUrl::fromString('https://safe.fakturownia.pl', ['safe.fakturownia.pl']),
        $secret,
    );
    $profile = new ConnectionProfile(
        new ConnectionKey('sensitive-connection-key'),
        DeploymentStage::Production,
        $config,
    );
    $client = (new DefaultClientFactory)->make($config);
    $connector = credentialChainConnector($client);
    $authenticator = $connector->getAuthenticator();
    $connection = new FakturowniaConnection(
        $profile->key(),
        $profile->deploymentStage,
        $client,
    );
    $resolver = new ConfigConnectionResolver(new Repository([
        'fakturownia' => [
            'connections' => [
                'sensitive-connection-key' => [
                    'deployment_stage' => 'production',
                    'base_url' => 'https://safe.fakturownia.pl',
                    'allowed_hosts' => ['safe.fakturownia.pl'],
                    'token' => 'chain-secret-token',
                    'connect_timeout_seconds' => 10,
                    'request_timeout_seconds' => 30,
                ],
            ],
        ],
    ]));
    $manager = new FakturowniaManager($resolver, new DefaultClientFactory);
    $credentialObjects = [
        'secret' => $secret,
        'config' => $config,
        'profile' => $profile,
        'connector' => $connector,
        'authenticator' => $authenticator,
        'client' => $client,
        'connection' => $connection,
        'resolver' => $resolver,
        'manager' => $manager,
    ];

    foreach ($credentialObjects as $credentialObject) {
        ob_start();
        var_dump($credentialObject);
        $debugOutput = (string) ob_get_clean();
        $symfonyDumpOutput = '';
        $dumper = new CliDumper(function (string $line, int $depth) use (&$symfonyDumpOutput): void {
            $symfonyDumpOutput .= $line."\n";
        });
        $dumper->dump((new VarCloner)->cloneVar($credentialObject));
        $printOutput = (string) print_r($credentialObject, true);
        $exportOutput = (string) var_export($credentialObject, true);
        $jsonOutput = json_encode($credentialObject, JSON_THROW_ON_ERROR);

        foreach ([$debugOutput, $symfonyDumpOutput, $printOutput, $exportOutput, $jsonOutput] as $output) {
            expect($output)
                ->not->toContain('chain-secret-token')
                ->not->toContain('sensitive-connection-key')
                ->not->toContain('safe.fakturownia.pl')
                ->not->toContain('https://safe.fakturownia.pl');
        }

        expect(fn () => clone $credentialObject)->toThrow(LogicException::class)
            ->and(fn (): string => serialize($credentialObject))->toThrow(Exception::class);
    }
});
