<?php

declare(strict_types=1);

function s814ArchitectureContents(string $relativePath): string
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read the S8.14 source file {$relativePath}.");
    }

    return $contents;
}

/** @return list<string> */
function s814WebhookSourceFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [
        ...glob($root.'/src/Stateful/Webhooks/**/*.php') ?: [],
        ...glob($root.'/src/Stateful/Webhooks/*.php') ?: [],
        ...glob($root.'/src/Laravel/Webhooks/*.php') ?: [],
    ];
    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

it('freezes the provider-owned webhook migration as an independent contract', function (): void {
    $path = dirname(__DIR__, 2).'/database/migrations/2026_08_26_000002_create_fakturownia_webhook_receipts_table.php';
    $migration = file_get_contents($path);

    expect($migration)->not->toBeFalse()
        ->and(hash_file('sha256', $path))->toBe('2c5190e04525bab7f796128cbd562419a19613c861426ec3ffb7cc5309f28749')
        ->and($migration)->toContain("Schema::create('fakturownia_webhook_receipts'")
        ->and($migration)->toContain('BEFORE TRUNCATE ON {{table}}')
        ->and($migration)->toContain('{{row_function}}')
        ->and($migration)->toContain('{{truncate_function}}')
        ->and($migration)->not->toContain('foreignId')
        ->and($migration)->not->toContain('references(')
        ->and($migration)->not->toContain('CASCADE');
});

it('keeps webhook intake free of outbound HTTP and operation terminalization dependencies', function (): void {
    foreach (s814WebhookSourceFiles() as $file) {
        $contents = file_get_contents($file);

        expect($contents)->not->toBeFalse()
            ->and($contents)->not->toContain('Saloon\\')
            ->and($contents)->not->toContain('Guzzle\\')
            ->and($contents)->not->toContain('Facades\\Http')
            ->and($contents)->not->toContain('Cieplik206\\Fakturownia\\Read\\')
            ->and($contents)->not->toContain('Cieplik206\\Fakturownia\\Client\\')
            ->and($contents)->not->toContain('Cieplik206\\IntegrationOperations\\')
            ->and($contents)->not->toContain('OutcomeProjector')
            ->and($contents)->not->toContain('TerminalOutcome');
    }
});

it('keeps the production receiver unbound and hard-disabled before S8.1 evidence', function (): void {
    $receiver = s814ArchitectureContents('src/Laravel/Webhooks/ReceiveWebhookAction.php');
    $provider = s814ArchitectureContents('src/Laravel/FakturowniaServiceProvider.php');
    $gatePosition = strpos($receiver, '(new DeferredWebhookCapabilityGate)->assertActive();');
    $recordPosition = strpos($receiver, 'return $this->recorder->record($delivery);');

    if (! is_int($gatePosition) || ! is_int($recordPosition)) {
        throw new RuntimeException('The deferred webhook gate must execute before the recorder.');
    }

    expect($gatePosition)->toBeLessThan($recordPosition)
        ->and($provider)->not->toContain('ReceiveWebhookAction')
        ->and($provider)->not->toContain('RecordWebhookHintAction')
        ->and($provider)->not->toContain('DeferredWebhookCapabilityGate');
});

it('pins deferred signatures and non-authoritative receipt semantics', function (): void {
    $verifier = s814ArchitectureContents('src/Laravel/Webhooks/DeferredWebhookSignatureVerifier.php');
    $receipt = s814ArchitectureContents('src/Stateful/Webhooks/WebhookInboxReceipt.php');
    $repository = s814ArchitectureContents('src/Laravel/Webhooks/DatabaseWebhookInboxRepository.php');

    expect($verifier)->toContain('return WebhookSignatureVerification::Unverified;')
        ->and($verifier)->not->toContain('rawBody()')
        ->and($verifier)->not->toContain('headers()')
        ->and($receipt)->toContain("public function requiresAuthoritativeRead(): bool\n    {\n        return true;")
        ->and($receipt)->toContain("public function mayTerminalizeOperation(): bool\n    {\n        return false;")
        ->and($repository)->toContain("getDriverName() !== 'pgsql'")
        ->and($repository)->toContain('current_database() AS database_name, current_schema() AS schema_name')
        ->and($repository)->toContain('$expectedSchema.\'.fakturownia_webhook_receipts\'')
        ->and($repository)->toContain('pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended');
});
