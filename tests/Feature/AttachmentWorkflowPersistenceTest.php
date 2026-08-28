<?php

declare(strict_types=1);

use Cieplik206\Fakturownia\Laravel\Attachments\DatabaseAttachmentWorkflowStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Attachments\Upload\AttachmentBinaryUploadedResult;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AttachmentUploadProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

function attachmentWorkflowProjectionPlan(): AttachmentUploadProjectionPlan
{
    $revisionHmac = str_repeat('a', 64);

    return new AttachmentUploadProjectionPlan(
        new ConnectionKey('accounting'),
        new OperationId('01K3K8N8G8V3A6R5T4Y2W1R8B1'),
        new AttachmentBinaryUploadedResult(
            '910123',
            new InvoiceResourceId('01K3K8N8G8V3A6R5T4Y2W1R8B0'),
            ArtifactId::fromRevisionHmac($revisionHmac),
            'invoice_123.pdf',
            new ArtifactObjectDescriptor(
                'shared-artifacts',
                ContentAddress::fromSha256(str_repeat('c', 64)),
                'application/pdf',
                1_024,
            ),
            2,
            $revisionHmac,
            str_repeat('b', 64),
        ),
        IntegrationContext::make('cost:123:attachment'),
    );
}

it('persists, recovers, links, and finalizes an attachment workflow idempotently', function (): void {
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);

    $store = $this->app->make(DatabaseAttachmentWorkflowStore::class);
    $plan = attachmentWorkflowProjectionPlan();
    $finalizeId = new OperationId('01K3K8N8G8V3A6R5T4Y2W1R8B2');

    expect(fn () => $store->applyUpload($plan))
        ->toThrow(LogicException::class, 'kernel terminal transaction');

    DB::transaction(function () use ($store, $plan): void {
        $store->applyUpload($plan);
        $store->applyUpload($plan);
    });

    $pending = $store->pendingFinalize(50);

    expect($pending)->toHaveCount(1)
        ->and($pending[0]->uploadOperationId->equals($plan->uploadOperationId))->toBeTrue()
        ->and($pending[0]->remoteId)->toBe('910123')
        ->and(DB::table('fakturownia_attachment_workflows')->count())->toBe(1);

    $store->linkFinalize($plan->uploadOperationId, $finalizeId);
    $store->linkFinalize($plan->uploadOperationId, $finalizeId);

    expect($store->pendingFinalize(50))->toBe([])
        ->and(fn () => $store->linkFinalize(
            $plan->uploadOperationId,
            new OperationId('01K3K8N8G8V3A6R5T4Y2W1R8B3'),
        ))->toThrow(RuntimeException::class, 'another finalize operation');

    expect(fn () => $store->markFinalized($plan->uploadOperationId, $finalizeId))
        ->toThrow(LogicException::class, 'kernel terminal transaction');

    DB::transaction(function () use ($store, $plan, $finalizeId): void {
        $store->markFinalized($plan->uploadOperationId, $finalizeId);
        $store->markFinalized($plan->uploadOperationId, $finalizeId);
    });

    $row = DB::table('fakturownia_attachment_workflows')
        ->where('upload_operation_id', $plan->uploadOperationId->value)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->finalize_operation_id ?? null)->toBe($finalizeId->value)
        ->and($row->finalize_accepted_at ?? null)->not->toBeNull()
        ->and($row->finalized_at ?? null)->not->toBeNull();
});

it('rejects invalid recovery bounds', function (): void {
    $store = $this->app->make(DatabaseAttachmentWorkflowStore::class);

    expect(fn (): array => $store->pendingFinalize(0))
        ->toThrow(LogicException::class)
        ->and(fn (): array => $store->pendingFinalize(101))
        ->toThrow(LogicException::class);
});
