<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\AuthoritativeDownloadInvoicePdfOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\AuthoritativeDownloadInvoicePdfReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfConfiguration;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfSourceReader;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfCommand;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationFactory;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationFailure;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfOperationHandler;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\DownloadInvoicePdfPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfReadyResult;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfReadyResultCodec;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfStager;
use Cieplik206\Fakturownia\Stateful\Invoices\Operations\IssueInvoiceResult;
use Cieplik206\Fakturownia\Stateful\Ksef\InvoiceKsefState;
use Cieplik206\Fakturownia\Stateful\Ksef\OpenKsefStatus;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResource;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\Fakturownia\Tests\Support\Stateful\InvoiceFixtures;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\BoundaryMode;
use Cieplik206\IntegrationOperations\Enums\InitialOperationLane;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\Enums\SuccessEffectPolicy;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinitionValidator;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationObservation;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use DateTimeImmutable;
use LogicException;

final class S66MemoryStream extends ArtifactContentStream
{
    private int $offset = 0;

    private bool $closed = false;

    public function __construct(private readonly string $bytes) {}

    public function read(int $maximumBytes): string
    {
        if ($this->closed) {
            throw new LogicException('The test artifact stream is closed.');
        }

        $chunk = substr($this->bytes, $this->offset, $maximumBytes);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->closed || $this->offset >= strlen($this->bytes);
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

final class S66PdfSource implements InvoicePdfSourceReader
{
    public int $calls = 0;

    public function __construct(public string $bytes) {}

    public function open(ConnectionKey $connectionKey, string $remoteId): ArtifactContentStream
    {
        $this->calls++;

        return new S66MemoryStream($this->bytes);
    }
}

final class S66ContentStore implements ContentAddressedArtifactStore
{
    /** @var array<string, string> */
    public array $objects = [];

    public int $putCalls = 0;

    public bool $loseResponse = false;

    public function put(ArtifactContentStream $content, string $mimeType): ArtifactObjectDescriptor
    {
        $this->putCalls++;
        $bytes = '';

        while (! $content->eof()) {
            $bytes .= $content->read(1024);
        }

        $address = ContentAddress::fromSha256(hash('sha256', $bytes));
        $this->objects[(string) $address] = $bytes;
        $descriptor = new ArtifactObjectDescriptor('shared-artifacts', $address, $mimeType, strlen($bytes));

        if ($this->loseResponse) {
            $this->loseResponse = false;
            throw new RuntimeException('Simulated response loss after durable storage.');
        }

        return $descriptor;
    }

    public function inspect(ContentAddress $contentAddress): ?ArtifactObjectDescriptor
    {
        $bytes = $this->objects[(string) $contentAddress] ?? null;

        return is_string($bytes)
            ? new ArtifactObjectDescriptor('shared-artifacts', $contentAddress, 'application/pdf', strlen($bytes))
            : null;
    }

    public function open(ContentAddress $contentAddress): ArtifactContentStream
    {
        $bytes = $this->objects[(string) $contentAddress] ?? null;

        if (! is_string($bytes)) {
            throw new LogicException('The test artifact object is absent.');
        }

        return new S66MemoryStream($bytes);
    }
}

final class S66EffectBoundary implements EffectBoundary
{
    public int $openCalls = 0;

    public function open(): void
    {
        $this->openCalls++;
    }

    public function wasOpened(): bool
    {
        return $this->openCalls > 0;
    }
}

final readonly class S66Execution implements OperationExecution
{
    public function __construct(
        private CanonicalObject $canonicalPayload,
        private EffectBoundary $boundary,
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'sales');
    }

    public function operationType(): OperationType
    {
        return new OperationType(DownloadInvoicePdfOperationDefinitionProvider::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('workflow:pdf:1');
    }

    public function payload(): CanonicalObject
    {
        return $this->canonicalPayload;
    }

    public function effectBoundary(): EffectBoundary
    {
        return $this->boundary;
    }
}

final readonly class S66ReconciliationContext implements AuthoritativeReconciliationContext
{
    public function __construct(private CanonicalObject $canonicalPayload) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'sales');
    }

    public function operationType(): OperationType
    {
        return new OperationType(DownloadInvoicePdfOperationDefinitionProvider::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('workflow:pdf:1');
    }

    public function payload(): CanonicalObject
    {
        return $this->canonicalPayload;
    }

    public function observationNumber(): int
    {
        return 1;
    }

    public function effectPossiblyStartedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-26T10:00:00+00:00');
    }

    public function observationStartedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-26T10:01:00+00:00');
    }

    /** @return list<ReconciliationObservation> */
    public function priorObservations(): array
    {
        return [];
    }

    public function reconciliationTrigger(): ReconciliationTrigger
    {
        return ReconciliationTrigger::LostResponse;
    }
}

function s66Pdf(string $marker = 'invoice'): string
{
    return "%PDF-1.7\n{$marker}\n%%EOF\n";
}

function s66Command(int $generation = 1): DownloadInvoicePdfCommand
{
    return new DownloadInvoicePdfCommand(
        new ConnectionKey('sales'),
        new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1Q9P7'),
        '9001',
        new VersionedHmacDigest(7, LookupHmacDomain::Payload, str_repeat('a', 64)),
        1,
        null,
        null,
        'default',
        new VersionedHmacDigest(7, LookupHmacDomain::Payload, str_repeat('b', 64)),
        $generation,
        20 * 1_048_576,
    );
}

function s66Payload(): CanonicalObject
{
    return (new DownloadInvoicePdfPayloadCodec)->encode(s66Command());
}

function s66InvoiceResource(int $rowVersion = 1): InvoiceResource
{
    $draft = InvoiceFixtures::draft();
    $result = new IssueInvoiceResult(
        remoteId: '9001',
        number: 'FV/2026/08/9001',
        kind: $draft->kind,
        status: 'issued',
        issueDate: $draft->issueDate,
        buyerTaxNumber: '1234567890',
        totalGross: $draft->totalGross(),
        oid: 'OID-ORDER-9001',
        positions: $draft->positions,
    );
    $created = new DateTimeImmutable('2026-08-26T10:00:00+00:00');

    return new InvoiceResource(
        new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1Q9P7'),
        new ConnectionKey('sales'),
        InvoiceResource::LocalReferenceType,
        new VersionedHmacDigest(7, LookupHmacDomain::Intent, str_repeat('c', 64)),
        '9001',
        'FV/2026/08/9001',
        new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7'),
        new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P7'),
        $result,
        new VersionedHmacDigest(7, LookupHmacDomain::Payload, str_repeat('d', 64)),
        $rowVersion,
        $created,
        $created,
        $created,
    );
}

function s66AcceptedKsefState(): InvoiceKsefState
{
    $observed = new DateTimeImmutable('2026-08-26T10:05:00+00:00');

    return new InvoiceKsefState(
        new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1Q9P7'),
        new ConnectionKey('sales'),
        '9001',
        new OperationId('01K3K8N8G8V3A6R5T4Y2W1Q9P8'),
        new OpenKsefStatus('ok'),
        'KSEF-2026-9001',
        0,
        false,
        false,
        false,
        str_repeat('e', 64),
        1,
        $observed,
        $observed,
        null,
        null,
    );
}

it('registers one immediate boundary-required content-addressed effect', function (): void {
    $definition = iterator_to_array(AuthoritativeDownloadInvoicePdfOperationDefinitionProvider::definitions())[0] ?? null;

    expect($definition)->not->toBeNull();
    (new AuthoritativeOperationDefinitionValidator)->assertValid($definition);

    expect($definition->maximumRemoteWrites)->toBe(1)
        ->and($definition->boundaryMode)->toBe(BoundaryMode::Required)
        ->and($definition->initialLane)->toBe(InitialOperationLane::Execute)
        ->and($definition->successEffectPolicy)->toBe(SuccessEffectPolicy::MustBeAppliedByOperation)
        ->and($definition->polling)->toBeNull()
        ->and($definition->transportTargets)->toHaveCount(2)
        ->and($definition->transportTargets[0]->targetId)->toBe('invoice.pdf.artifact_put')
        ->and($definition->transportTargets[0]->method)->toBe('PUT')
        ->and($definition->transportTargets[0]->targetTemplate)->toBe('/{content_address}')
        ->and($definition->transportTargets[1]->targetId)->toBe('invoice.pdf.read')
        ->and($definition->transportTargets[1]->method)->toBe('GET')
        ->and($definition->transportTargets[1]->targetTemplate)->toBe('/invoices/{remote_id}.pdf');
});

it('stages and validates the PDF before opening exactly one effect boundary', function (): void {
    $source = new S66PdfSource(s66Pdf());
    $store = new S66ContentStore;
    $boundary = new S66EffectBoundary;
    $handler = new DownloadInvoicePdfOperationHandler($source, $store, new InvoicePdfStager);
    $outcome = $handler->execute(new S66Execution(s66Payload(), $boundary));

    expect($outcome->result)->toBeInstanceOf(InvoicePdfReadyResult::class)
        ->and($source->calls)->toBe(1)
        ->and($store->putCalls)->toBe(1)
        ->and($boundary->openCalls)->toBe(1);

    $result = $outcome->result;

    if (! $result instanceof InvoicePdfReadyResult) {
        throw new LogicException('The invoice PDF handler returned an invalid result.');
    }

    expect((new InvoicePdfReadyResultCodec)->decode(
        (new InvoicePdfReadyResultCodec)->encode($result),
    ))->toEqual($result);
});

it('rejects malformed PDF bytes before the durable effect can start', function (): void {
    $source = new S66PdfSource('not-a-pdf');
    $store = new S66ContentStore;
    $boundary = new S66EffectBoundary;

    expect(fn () => (new DownloadInvoicePdfOperationHandler(
        $source,
        $store,
        new InvoicePdfStager,
    ))->execute(new S66Execution(s66Payload(), $boundary)))
        ->toThrow(DownloadInvoicePdfOperationFailure::class);

    expect($store->putCalls)->toBe(0)
        ->and($boundary->openCalls)->toBe(0);
});

it('recovers an exact result after losing the storage response without a second write', function (): void {
    $source = new S66PdfSource(s66Pdf('lost-response'));
    $store = new S66ContentStore;
    $store->loseResponse = true;
    $boundary = new S66EffectBoundary;
    $handler = new DownloadInvoicePdfOperationHandler($source, $store, new InvoicePdfStager);

    expect(fn () => $handler->execute(new S66Execution(s66Payload(), $boundary)))
        ->toThrow(DownloadInvoicePdfOperationFailure::class);

    $reconciled = (new AuthoritativeDownloadInvoicePdfReconciliationStrategy(
        $source,
        $store,
        new InvoicePdfStager,
    ))->reconcile(new S66ReconciliationContext(s66Payload()));

    expect($reconciled->result)->toBe(AuthoritativeReconciliationResult::FoundExact)
        ->and($reconciled->operationResult)->toBeInstanceOf(InvoicePdfReadyResult::class)
        ->and($store->putCalls)->toBe(1)
        ->and($boundary->openCalls)->toBe(1);
});

it('terminalizes a conclusively absent exact address without writing during reconciliation', function (): void {
    $source = new S66PdfSource(s66Pdf('absent'));
    $store = new S66ContentStore;
    $reconciled = (new AuthoritativeDownloadInvoicePdfReconciliationStrategy(
        $source,
        $store,
        new InvoicePdfStager,
    ))->reconcile(new S66ReconciliationContext(s66Payload()));

    expect($reconciled->result)->toBe(AuthoritativeReconciliationResult::AbsentConclusive)
        ->and($reconciled->safeFailure?->code)->toBe('fakturownia_invoice_pdf_object_absent')
        ->and($store->putCalls)->toBe(0);
});

it('changes the immutable revision for resource and accepted KSeF snapshots but not retry generation', function (): void {
    $configuration = new class implements InvoicePdfConfiguration
    {
        public function maximumBytes(): int
        {
            return 20 * 1_048_576;
        }
    };
    $factory = new DownloadInvoicePdfOperationFactory(InvoiceFixtures::hmac(), $configuration);
    $context = IntegrationContext::make('workflow:pdf:revision');
    $before = $factory->make(s66InvoiceResource(), null, $context);
    $afterKsef = $factory->make(s66InvoiceResource(), s66AcceptedKsefState(), $context);
    $afterResourceChange = $factory->make(s66InvoiceResource(2), null, $context);
    $retryGeneration = $factory->make(s66InvoiceResource(), null, $context, generation: 2);
    $codec = new DownloadInvoicePdfPayloadCodec;
    $beforeCommand = $codec->decode($before->payload);
    $afterKsefCommand = $codec->decode($afterKsef->payload);
    $afterResourceCommand = $codec->decode($afterResourceChange->payload);
    $retryCommand = $codec->decode($retryGeneration->payload);

    expect($beforeCommand->revisionKey->equals($afterKsefCommand->revisionKey))->toBeFalse()
        ->and($beforeCommand->revisionKey->equals($afterResourceCommand->revisionKey))->toBeFalse()
        ->and($beforeCommand->revisionKey->equals($retryCommand->revisionKey))->toBeTrue()
        ->and($before->intent->localReference?->identifier())->toEndWith(':1')
        ->and($retryGeneration->intent->localReference?->identifier())->toEndWith(':2');
});
