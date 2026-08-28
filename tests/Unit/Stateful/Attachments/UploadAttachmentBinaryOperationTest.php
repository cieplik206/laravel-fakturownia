<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfStager;
use Cieplik206\Fakturownia\Stateful\Attachments\AttachmentSourceStager;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\AttachmentOperationFailure;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\AttachmentRetryPolicy;
use Cieplik206\Fakturownia\Stateful\Attachments\Operations\Contracts\UploadAttachmentBinaryTransport;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\AttachmentBinaryUploadedResult;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\AttachmentBinaryUploadedResultCodec;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\AuthoritativeUploadAttachmentBinaryOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\AuthoritativeUploadAttachmentBinaryReconciliationStrategy;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\DisabledUploadAttachmentBinaryTransport;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\UploadAttachmentBinaryCommand;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\UploadAttachmentBinaryOperationDefinitionProvider;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\UploadAttachmentBinaryOperationFactory;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\UploadAttachmentBinaryOperationHandler;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\UploadAttachmentBinaryPayloadCodec;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\EffectBoundary;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\Enums\RetryDecision;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeOperationDefinitionValidator;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionValidator;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationObservation;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use DateTimeImmutable;
use LogicException;

final class S88MemoryStream extends ArtifactContentStream
{
    private int $offset = 0;

    private bool $closed = false;

    public function __construct(private readonly string $bytes) {}

    public function read(int $maximumBytes): string
    {
        if ($this->closed) {
            throw new LogicException('The attachment test stream is closed.');
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

final class S88ContentStore implements ContentAddressedArtifactStore
{
    /** @var array<string, string> */
    public array $objects = [];

    public function put(ArtifactContentStream $content, string $mimeType): ArtifactObjectDescriptor
    {
        $bytes = '';

        while (! $content->eof()) {
            $bytes .= $content->read(1024);
        }

        $address = ContentAddress::fromSha256(hash('sha256', $bytes));
        $this->objects[(string) $address] = $bytes;

        return new ArtifactObjectDescriptor('shared-artifacts', $address, $mimeType, strlen($bytes));
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
            throw new LogicException('The attachment test object is absent.');
        }

        return new S88MemoryStream($bytes);
    }
}

final class S88EffectBoundary implements EffectBoundary
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

final class S88UploadTransport implements UploadAttachmentBinaryTransport
{
    public int $calls = 0;

    public string $bytes = '';

    public function upload(
        ConnectionKey $connection,
        string $remoteId,
        string $fileName,
        ContentAddress $contentAddress,
        int $sizeBytes,
        ArtifactContentStream $content,
        EffectBoundary $boundary,
    ): void {
        $this->calls++;
        $boundary->open();

        while (! $content->eof()) {
            $this->bytes .= $content->read(1024);
        }

        expect(strlen($this->bytes))->toBe($sizeBytes)
            ->and(hash('sha256', $this->bytes))->toBe($contentAddress->sha256());
    }
}

final readonly class S88OperationExecution implements OperationExecution
{
    public function __construct(
        private CanonicalObject $canonicalPayload,
        private EffectBoundary $boundary,
    ) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1R8A1');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'accounting');
    }

    public function operationType(): OperationType
    {
        return new OperationType(UploadAttachmentBinaryOperationFactory::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('attachment-upload:1');
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

final readonly class S88ReconciliationContext implements AuthoritativeReconciliationContext
{
    public function __construct(private CanonicalObject $canonicalPayload) {}

    public function operationId(): OperationId
    {
        return new OperationId('01K3K8N8G8V3A6R5T4Y2W1R8A1');
    }

    public function scope(): IntegrationScope
    {
        return IntegrationScope::of('fakturownia', 'accounting');
    }

    public function operationType(): OperationType
    {
        return new OperationType(UploadAttachmentBinaryOperationFactory::OperationType);
    }

    public function context(): IntegrationContext
    {
        return IntegrationContext::make('attachment-upload:1');
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
        return new DateTimeImmutable('2026-08-28T10:00:00+00:00');
    }

    public function observationStartedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-28T10:01:00+00:00');
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

function s88Pdf(): string
{
    return "%PDF-1.7\nattachment-source\n%%EOF\n";
}

function s88Command(ArtifactObjectDescriptor $object): UploadAttachmentBinaryCommand
{
    return new UploadAttachmentBinaryCommand(
        new ConnectionKey('accounting'),
        '910123',
        new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1R8A0'),
        'cost:123:attachment:'.substr($object->contentAddress->sha256(), 0, 24),
        'invoice_123.pdf',
        $object->contentAddress,
        $object->mimeType,
        $object->sizeBytes,
        0,
        str_repeat('a', 64),
        str_repeat('b', 64),
    );
}

it('stages a bounded immutable PDF and executes exactly one upload effect', function (): void {
    $store = new S88ContentStore;
    $source = (new AttachmentSourceStager(new InvoicePdfStager, $store))->stage(
        new S88MemoryStream(s88Pdf()),
        'invoice_123.pdf',
    );
    $command = s88Command($source->object);
    $codec = new UploadAttachmentBinaryPayloadCodec;
    $payload = $codec->encode($command);
    $accepted = (new UploadAttachmentBinaryOperationFactory)->make(
        $command,
        IntegrationContext::make('attachment-upload:1'),
    );
    $boundary = new S88EffectBoundary;
    $transport = new S88UploadTransport;
    $outcome = (new UploadAttachmentBinaryOperationHandler($store, $transport))->execute(
        new S88OperationExecution($payload, $boundary),
    );
    $encoded = (new AttachmentBinaryUploadedResultCodec)->encode($outcome->result);
    $decoded = (new AttachmentBinaryUploadedResultCodec)->decode($encoded);

    expect($codec->decode($payload))->toEqual($command)
        ->and($accepted->intent->localReference?->type)->toBe('invoice_attachment_upload')
        ->and($transport->calls)->toBe(1)
        ->and($boundary->openCalls)->toBe(1)
        ->and($transport->bytes)->toBe(s88Pdf())
        ->and($decoded)->toBeInstanceOf(AttachmentBinaryUploadedResult::class)
        ->and($decoded)->toEqual($outcome->result);
});

it('keeps upload disabled before the effect boundary and freezes both registries', function (): void {
    $store = new S88ContentStore;
    $source = (new AttachmentSourceStager(new InvoicePdfStager, $store))->stage(
        new S88MemoryStream(s88Pdf()),
        'invoice_123.pdf',
    );
    $payload = (new UploadAttachmentBinaryPayloadCodec)->encode(s88Command($source->object));
    $boundary = new S88EffectBoundary;
    $regular = iterator_to_array(UploadAttachmentBinaryOperationDefinitionProvider::definitions(), false)[0];
    $authoritative = iterator_to_array(
        AuthoritativeUploadAttachmentBinaryOperationDefinitionProvider::definitions(),
        false,
    )[0];

    expect(fn () => (new UploadAttachmentBinaryOperationHandler(
        $store,
        new DisabledUploadAttachmentBinaryTransport,
    ))->execute(new S88OperationExecution($payload, $boundary)))
        ->toThrow(AttachmentOperationFailure::class, 'not enabled by reviewed live evidence')
        ->and($boundary->openCalls)->toBe(0)
        ->and($authoritative->transportTargets[0]->transport)->toBe('https_multipart')
        ->and((new OperationDefinitionValidator)->violations($regular))->toBe([])
        ->and((new AuthoritativeOperationDefinitionValidator)->violations($authoritative))->toBe([]);
});

it('never repeats an uncertain upload without remote evidence', function (): void {
    $store = new S88ContentStore;
    $source = (new AttachmentSourceStager(new InvoicePdfStager, $store))->stage(
        new S88MemoryStream(s88Pdf()),
        'invoice_123.pdf',
    );
    $payload = (new UploadAttachmentBinaryPayloadCodec)->encode(s88Command($source->object));
    $failure = new FailureClassification(
        FailureDisposition::Uncertain,
        new SafeOperationFailure('test_upload_unknown', 'The upload outcome is unknown.'),
    );
    $decision = (new AttachmentRetryPolicy)->decide(
        new S88OperationExecution($payload, new S88EffectBoundary),
        $failure,
    );
    $reconciliation = (new AuthoritativeUploadAttachmentBinaryReconciliationStrategy($store))->reconcile(
        new S88ReconciliationContext($payload),
    );

    expect($decision->decision)->toBe(RetryDecision::Reconcile)
        ->and($reconciliation->result)->toBe(AuthoritativeReconciliationResult::Inconclusive)
        ->and($reconciliation->evidenceCode)->toBe('fakturownia.attachment.upload.remote_evidence_required');
});
