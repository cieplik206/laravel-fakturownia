<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfStager;
use Cieplik206\Fakturownia\Stateful\Attachments\AttachmentSourceStager;
use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\FinalizeAttachmentOperationFactory;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\UploadAttachmentBinaryOperationFactory;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AdvanceAttachmentWorkflow;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AttachInvoiceCommand;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AttachmentUploadProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AttachmentWorkflowCoordinator;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\Contracts\AttachmentWorkflowStore;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\PendingAttachmentFinalize;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Contracts\OperationCoordinator;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\SupersedeFailedOperation;
use LogicException;

final class S89WorkflowStream extends ArtifactContentStream
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes) {}

    public function read(int $maximumBytes): string
    {
        $chunk = substr($this->bytes, $this->offset, $maximumBytes);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->bytes);
    }

    public function close(): void {}
}

final class S89WorkflowObjects implements ContentAddressedArtifactStore
{
    /** @var array<string, string> */
    private array $objects = [];

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
            throw new LogicException('The workflow test object is missing.');
        }

        return new S89WorkflowStream($bytes);
    }
}

final class S89WorkflowOperations implements OperationCoordinator
{
    /** @var list<AcceptOperation> */
    public array $accepted = [];

    public function accept(AcceptOperation $command): OperationReceipt
    {
        $this->accepted[] = $command;
        $id = $command->operationType->value === UploadAttachmentBinaryOperationFactory::OperationType
            ? '01K3K8N8G8V3A6R5T4Y2W1R8A1'
            : '01K3K8N8G8V3A6R5T4Y2W1R8A2';

        return new OperationReceipt(
            new OperationId($id),
            $command->scope,
            $command->operationType,
            count($this->accepted) > 2,
        );
    }

    public function supersedeFailed(SupersedeFailedOperation $command): OperationReceipt
    {
        throw new LogicException('Superseding is outside this workflow test.');
    }
}

final class S89WorkflowStore implements AttachmentWorkflowStore
{
    /** @var list<array{string, string}> */
    public array $links = [];

    public function applyUpload(AttachmentUploadProjectionPlan $plan): void {}

    public function pendingFinalize(int $limit): array
    {
        return [];
    }

    public function linkFinalize(OperationId $uploadOperationId, OperationId $finalizeOperationId): void
    {
        $this->links[] = [$uploadOperationId->value, $finalizeOperationId->value];
    }

    public function markFinalized(OperationId $uploadOperationId, OperationId $finalizeOperationId): void {}
}

it('returns an upload receipt and advances recovery through a distinct finalize receipt', function (): void {
    $objects = new S89WorkflowObjects;
    $operations = new S89WorkflowOperations;
    $store = new S89WorkflowStore;
    $workflow = new AttachmentWorkflowCoordinator(
        new AttachmentSourceStager(new InvoicePdfStager, $objects),
        new UploadAttachmentBinaryOperationFactory,
        $operations,
    );
    $receipt = $workflow->attach(new AttachInvoiceCommand(
        new ConnectionKey('accounting'),
        '910123',
        new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1R8A0'),
        'cost:123:attachment:invoice-123',
        'invoice_123.pdf',
        new S89WorkflowStream("%PDF-1.7\nattachment\n%%EOF\n"),
        0,
        str_repeat('a', 64),
        str_repeat('b', 64),
        IntegrationContext::make('cost:123'),
    ));
    $object = $objects->inspect(ContentAddress::fromSha256(hash(
        'sha256',
        "%PDF-1.7\nattachment\n%%EOF\n",
    )));

    if (! $object instanceof ArtifactObjectDescriptor) {
        throw new LogicException('Expected the staged workflow object.');
    }

    $finalizeReceipt = (new AdvanceAttachmentWorkflow(
        new FinalizeAttachmentOperationFactory,
        $operations,
        $store,
    ))->advance(new PendingAttachmentFinalize(
        new ConnectionKey('accounting'),
        $receipt->uploadReceipt->operationId,
        new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1R8A0'),
        '910123',
        ArtifactId::fromRevisionHmac(str_repeat('a', 64)),
        'invoice_123.pdf',
        $object,
        0,
        str_repeat('a', 64),
        str_repeat('b', 64),
    ));

    expect($receipt->workflowId())->toBe('01K3K8N8G8V3A6R5T4Y2W1R8A1')
        ->and($receipt->uploadReceipt->operationType->value)->toBe(UploadAttachmentBinaryOperationFactory::OperationType)
        ->and($finalizeReceipt->operationId->value)->toBe('01K3K8N8G8V3A6R5T4Y2W1R8A2')
        ->and($finalizeReceipt->operationType->value)->toBe(FinalizeAttachmentOperationFactory::OperationType)
        ->and($store->links)->toBe([[
            '01K3K8N8G8V3A6R5T4Y2W1R8A1',
            '01K3K8N8G8V3A6R5T4Y2W1R8A2',
        ]]);
});
