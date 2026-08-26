<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Webhooks\DatabaseWebhookInboxRepository;
use Cieplik206\Fakturownia\Laravel\Webhooks\RecordWebhookHintAction;
use Cieplik206\Fakturownia\Laravel\Webhooks\SodiumWebhookPayloadProtector;
use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookClock;
use Cieplik206\Fakturownia\Stateful\Webhooks\Contracts\WebhookSignatureVerifier;
use Cieplik206\Fakturownia\Stateful\Webhooks\Exceptions\WebhookDeliveryIdentityCollision;
use Cieplik206\Fakturownia\Stateful\Webhooks\Exceptions\WebhookInboxStorageUnavailable;
use Cieplik206\Fakturownia\Stateful\Webhooks\ProviderWebhookDeliveryId;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookDelivery;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookHintTrust;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookSignatureVerification;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class S814FeatureClock implements WebhookClock
{
    public function __construct(public DateTimeImmutable $current) {}

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }
}

final readonly class S814FeatureSignatureVerifier implements WebhookSignatureVerifier
{
    public function __construct(private WebhookSignatureVerification $verification) {}

    public function verify(WebhookDelivery $delivery): WebhookSignatureVerification
    {
        return $this->verification;
    }
}

final class S814PostgresContext
{
    public static ?string $schema = null;

    public static ?string $database = null;

    public static ?string $previousDefaultConnection = null;
}

/**
 * @return array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string, prefix: string, schema: string, sslmode: string}
 */
function s814PostgresConfiguration(string $schema = 'public'): array
{
    $host = getenv('FAKTUROWNIA_TEST_DB_HOST');
    $database = getenv('FAKTUROWNIA_TEST_DB_DATABASE');
    $username = getenv('FAKTUROWNIA_TEST_DB_USERNAME');
    $password = getenv('FAKTUROWNIA_TEST_DB_PASSWORD');

    if (! is_string($host) || $host === ''
        || ! is_string($database) || $database === ''
        || ! is_string($username) || $username === ''
        || ! is_string($password)) {
        throw new RuntimeException('The webhook PostgreSQL gate requires every FAKTUROWNIA_TEST_DB_* credential.');
    }

    if (getenv('FAKTUROWNIA_TEST_DB_ALLOW_SCHEMA') !== '1'
        || preg_match('/\A[a-z0-9_]+_(?:test|testing)\z/D', $database) !== 1) {
        throw new RuntimeException('The webhook PostgreSQL gate requires explicit opt-in and a test-only database.');
    }

    $port = getenv('FAKTUROWNIA_TEST_DB_PORT');
    $sslMode = getenv('FAKTUROWNIA_TEST_DB_SSLMODE');

    return [
        'driver' => 'pgsql',
        'host' => $host,
        'port' => is_string($port) && ctype_digit($port) ? (int) $port : 5432,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => $schema,
        'sslmode' => is_string($sslMode) && $sslMode !== '' ? $sslMode : 'prefer',
    ];
}

function s814QuotedPostgresIdentifier(string $identifier): string
{
    if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D', $identifier) !== 1) {
        throw new InvalidArgumentException('The generated webhook PostgreSQL identifier is invalid.');
    }

    return '"'.$identifier.'"';
}

function s814PreparePostgresInbox(): void
{
    $schema = 'fakturownia_webhook_'.getmypid().'_'.bin2hex(random_bytes(6));
    $quotedSchema = s814QuotedPostgresIdentifier($schema);
    $adminConfiguration = s814PostgresConfiguration();
    $testConfiguration = s814PostgresConfiguration($schema);
    $databaseManager = app(DatabaseManager::class);

    S814PostgresContext::$schema = $schema;
    S814PostgresContext::$database = $testConfiguration['database'];
    S814PostgresContext::$previousDefaultConnection = $databaseManager->getDefaultConnection();
    config()->set('database.connections.fakturownia_webhook_admin', $adminConfiguration);
    config()->set('database.connections.fakturownia_webhook_test', $testConfiguration);
    config()->set('integration-operations.database.connection', 'fakturownia_webhook_test');
    config()->set('integration-operations.database.schema', $schema);
    $databaseManager->purge('fakturownia_webhook_admin');
    $databaseManager->purge('fakturownia_webhook_test');
    $admin = $databaseManager->connection('fakturownia_webhook_admin');
    $authority = $admin->selectOne('SELECT current_database() AS database_name');

    if (! $authority instanceof stdClass
        || ! is_string($authority->database_name ?? null)
        || ! hash_equals($adminConfiguration['database'], $authority->database_name)) {
        throw new RuntimeException('The webhook gate connected to an unexpected PostgreSQL database.');
    }

    $admin->statement("CREATE SCHEMA {$quotedSchema}");
    $databaseManager->setDefaultConnection('fakturownia_webhook_test');
}

function s814MigrateWebhookInbox(bool $postgres = false): void
{
    if ($postgres) {
        s814PreparePostgresInbox();
    }

    $exitCode = Artisan::call('migrate', [
        '--path' => dirname(__DIR__, 2).'/database/migrations/2026_08_26_000002_create_fakturownia_webhook_receipts_table.php',
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0);
}

afterEach(function (): void {
    $schema = S814PostgresContext::$schema;

    if ($schema === null) {
        return;
    }

    $databaseManager = app(DatabaseManager::class);

    $databaseManager->purge('fakturownia_webhook_test');
    $admin = $databaseManager->connection('fakturownia_webhook_admin');
    $admin->statement('DROP SCHEMA '.s814QuotedPostgresIdentifier($schema).' CASCADE');
    $databaseManager->purge('fakturownia_webhook_admin');
    $databaseManager->setDefaultConnection(S814PostgresContext::$previousDefaultConnection ?? 'testing');
    config()->set('integration-operations.database.connection', null);
    config()->set('integration-operations.database.schema', 'public');
    S814PostgresContext::$schema = null;
    S814PostgresContext::$database = null;
    S814PostgresContext::$previousDefaultConnection = null;
});

function s814FeatureRecorder(
    S814FeatureClock $clock,
    WebhookSignatureVerification $verification = WebhookSignatureVerification::Unverified,
    int $windowSeconds = 300,
): RecordWebhookHintAction {
    return new RecordWebhookHintAction(
        new S814FeatureSignatureVerifier($verification),
        new SodiumWebhookPayloadProtector(str_repeat("\x20", 32), str_repeat("\x21", 32), 3),
        new DatabaseWebhookInboxRepository(
            DB::connection(),
            app('config'),
        ),
        $clock,
        $windowSeconds,
    );
}

function s814FeatureDelivery(?string $deliveryId, string $payload = '{"invoice":{"id":91}}'): WebhookDelivery
{
    return new WebhookDelivery(
        'tenant:feature',
        $deliveryId === null ? null : new ProviderWebhookDeliveryId($deliveryId),
        $payload,
        ['X-Fakturownia-Signature' => 'unverified'],
    );
}

it('owns a durable encrypted inbox without kernel foreign keys', function (): void {
    s814MigrateWebhookInbox();

    expect(Schema::hasTable('fakturownia_webhook_receipts'))->toBeTrue()
        ->and(Schema::getColumnListing('fakturownia_webhook_receipts'))->toBe([
            'id',
            'connection_key',
            'provider_delivery_id_hmac',
            'payload_hmac',
            'signature_status',
            'payload_key_version',
            'payload_cipher',
            'payload_nonce',
            'payload_ciphertext',
            'payload_ciphertext_sha256',
            'delivery_count',
            'received_at',
            'last_received_at',
        ])
        ->and(Schema::getForeignKeys('fakturownia_webhook_receipts'))->toBe([])
        ->and(Schema::hasIndex(
            'fakturownia_webhook_receipts',
            'fakturownia_webhook_receipts_connection_delivery_unique',
            'unique',
        ))->toBeTrue();
});

it('deduplicates provider redelivery and never sends outbound HTTP', function (): void {
    s814MigrateWebhookInbox(postgres: true);
    Http::fake();
    $clock = new S814FeatureClock(new DateTimeImmutable('2026-08-26 10:00:00.000000+00:00'));
    $recorder = s814FeatureRecorder($clock);
    $delivery = s814FeatureDelivery('delivery-redelivery-1');
    $first = $recorder->record($delivery);
    $clock->current = new DateTimeImmutable('2026-08-26 10:00:01.000000+00:00');
    $second = $recorder->record($delivery);
    $row = DB::table('fakturownia_webhook_receipts')->first();

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($second->deliveryCount)->toBe(2)
        ->and($second->trust)->toBe(WebhookHintTrust::Untrusted)
        ->and($second->requiresAuthoritativeRead())->toBeTrue()
        ->and($second->mayTerminalizeOperation())->toBeFalse()
        ->and(DB::table('fakturownia_webhook_receipts')->count())->toBe(1)
        ->and($row)->toBeInstanceOf(stdClass::class)
        ->and($row->provider_delivery_id_hmac ?? null)->toMatch('/^[a-f0-9]{64}$/')
        ->and($row->provider_delivery_id_hmac ?? null)->not->toContain('delivery-redelivery-1')
        ->and($row->payload_ciphertext ?? null)->not->toContain($delivery->rawBody());

    Http::assertNothingSent();
})->group('postgres');

it('rejects a provider delivery ID reused for a different payload', function (): void {
    s814MigrateWebhookInbox(postgres: true);
    $clock = new S814FeatureClock(new DateTimeImmutable('2026-08-26 10:10:00.000000+00:00'));
    $recorder = s814FeatureRecorder($clock);
    $recorder->record(s814FeatureDelivery('delivery-collision', '{"invoice":{"id":1}}'));

    expect(fn () => $recorder->record(s814FeatureDelivery('delivery-collision', '{"invoice":{"id":2}}')))
        ->toThrow(WebhookDeliveryIdentityCollision::class)
        ->and(DB::table('fakturownia_webhook_receipts')->count())->toBe(1)
        ->and(DB::table('fakturownia_webhook_receipts')->value('delivery_count'))->toBe(1);
})->group('postgres');

it('deduplicates missing delivery IDs by payload HMAC only inside the bounded window', function (): void {
    s814MigrateWebhookInbox(postgres: true);
    $clock = new S814FeatureClock(new DateTimeImmutable('2026-08-26 11:00:00.000000+00:00'));
    $recorder = s814FeatureRecorder($clock, windowSeconds: 300);
    $delivery = s814FeatureDelivery(null);
    $first = $recorder->record($delivery);
    $clock->current = new DateTimeImmutable('2026-08-26 11:04:59.000000+00:00');
    $second = $recorder->record($delivery);
    $clock->current = new DateTimeImmutable('2026-08-26 11:10:00.000000+00:00');
    $third = $recorder->record($delivery);

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($third->duplicate)->toBeFalse()
        ->and(DB::table('fakturownia_webhook_receipts')->count())->toBe(2)
        ->and(DB::table('fakturownia_webhook_receipts')->distinct()->pluck('payload_hmac'))->toHaveCount(1);
})->group('postgres');

it('can upgrade signature evidence on replay but cannot make a hint authoritative', function (): void {
    s814MigrateWebhookInbox(postgres: true);
    $clock = new S814FeatureClock(new DateTimeImmutable('2026-08-26 12:00:00.000000+00:00'));
    $delivery = s814FeatureDelivery('delivery-signature-upgrade');
    $unverified = s814FeatureRecorder($clock)->record($delivery);
    $clock->current = new DateTimeImmutable('2026-08-26 12:00:01.000000+00:00');
    $verified = s814FeatureRecorder($clock, WebhookSignatureVerification::Verified)->record($delivery);

    expect($unverified->trust)->toBe(WebhookHintTrust::Untrusted)
        ->and($verified->trust)->toBe(WebhookHintTrust::SignatureVerified)
        ->and($verified->requiresAuthoritativeRead())->toBeTrue()
        ->and($verified->mayTerminalizeOperation())->toBeFalse()
        ->and(DB::table('fakturownia_webhook_receipts')->value('signature_status'))->toBe('verified');
})->group('postgres');

it('binds repository authority to the expected PostgreSQL database and schema', function (): void {
    s814MigrateWebhookInbox(postgres: true);
    $database = S814PostgresContext::$database ?? throw new RuntimeException('Missing webhook test database.');
    $schema = S814PostgresContext::$schema ?? throw new RuntimeException('Missing webhook test schema.');

    new DatabaseWebhookInboxRepository(DB::connection(), app('config'));

    config()->set('integration-operations.database.schema', 'public');

    expect(fn (): DatabaseWebhookInboxRepository => new DatabaseWebhookInboxRepository(
        DB::connection(),
        app('config'),
    ))->toThrow(WebhookInboxStorageUnavailable::class);

    config()->set('integration-operations.database.schema', $schema);

    DB::statement('SET search_path TO public');

    expect(fn (): DatabaseWebhookInboxRepository => new DatabaseWebhookInboxRepository(
        DB::connection(),
        app('config'),
    ))->toThrow(WebhookInboxStorageUnavailable::class);
})->group('postgres');

it('rejects truncate and keeps durable webhook rows intact', function (): void {
    s814MigrateWebhookInbox(postgres: true);
    $clock = new S814FeatureClock(new DateTimeImmutable('2026-08-26 13:00:00.000000+00:00'));
    s814FeatureRecorder($clock)->record(s814FeatureDelivery('delivery-truncate-guard'));
    $schema = S814PostgresContext::$schema ?? throw new RuntimeException('Missing webhook test schema.');
    $qualifiedTable = s814QuotedPostgresIdentifier($schema).'."fakturownia_webhook_receipts"';

    expect(fn (): bool => DB::statement("TRUNCATE TABLE {$qualifiedTable}"))
        ->toThrow(QueryException::class)
        ->and(DB::table('fakturownia_webhook_receipts')->count())->toBe(1);
})->group('postgres');

it('deduplicates concurrent replay on the authoritative PostgreSQL writer', function (?string $deliveryId): void {
    s814MigrateWebhookInbox(postgres: true);
    $children = [];

    for ($worker = 0; $worker < 2; $worker++) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new RuntimeException('The webhook concurrency gate could not create a process barrier.');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('The webhook concurrency gate could not fork a worker.');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            fread($sockets[1], 1);

            try {
                $databaseManager = app(DatabaseManager::class);

                $databaseManager->purge('fakturownia_webhook_test');
                $databaseManager->setDefaultConnection('fakturownia_webhook_test');
                $clock = new S814FeatureClock(new DateTimeImmutable('2026-08-26 14:00:00.000000+00:00'));
                s814FeatureRecorder($clock)->record(s814FeatureDelivery($deliveryId));
                fwrite($sockets[1], 'ok');
                fclose($sockets[1]);
                exit(0);
            } catch (Throwable $exception) {
                fwrite($sockets[1], $exception::class.':'.$exception->getMessage());
                fclose($sockets[1]);
                exit(1);
            }
        }

        fclose($sockets[1]);
        $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
    }

    foreach ($children as $child) {
        fwrite($child['socket'], '1');
    }

    $results = [];

    foreach ($children as $child) {
        $status = 0;
        pcntl_waitpid($child['pid'], $status);
        $results[] = stream_get_contents($child['socket']);
        fclose($child['socket']);

        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0);
    }

    $databaseManager = app(DatabaseManager::class);

    $databaseManager->purge('fakturownia_webhook_test');
    $databaseManager->setDefaultConnection('fakturownia_webhook_test');

    expect($results)->toBe(['ok', 'ok'])
        ->and(DB::table('fakturownia_webhook_receipts')->count())->toBe(1)
        ->and(DB::table('fakturownia_webhook_receipts')->value('delivery_count'))->toBe(2);
})->with([
    'provider delivery ID' => 'delivery-concurrent',
    'payload-window fallback' => null,
])->group('postgres');

it('rejects non-PostgreSQL storage before making an authority query', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(fn (): DatabaseWebhookInboxRepository => new DatabaseWebhookInboxRepository(
        DB::connection(),
        app('config'),
    ))->toThrow(WebhookInboxStorageUnavailable::class)
        ->and($queries)->toBe(0);
});
