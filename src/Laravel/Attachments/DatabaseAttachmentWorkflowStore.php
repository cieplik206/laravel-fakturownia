<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Attachments;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactId;
use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AttachmentUploadProjectionPlan;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\Contracts\AttachmentWorkflowStore;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\PendingAttachmentFinalize;
use Cieplik206\Fakturownia\Stateful\Resources\InvoiceResourceId;
use Cieplik206\IntegrationOperations\Contracts\Clock;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use DateTimeInterface;
use Illuminate\Database\Connection;
use LogicException;
use RuntimeException;
use stdClass;

final readonly class DatabaseAttachmentWorkflowStore implements AttachmentWorkflowStore
{
    public function __construct(private KernelDatabase $database, private Clock $clock) {}

    public function applyUpload(AttachmentUploadProjectionPlan $plan): void
    {
        $connection = $this->connection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Attachment uploads must be projected inside the kernel terminal transaction.');
        }

        $result = $plan->result;
        $now = $this->timestamp($this->clock->now());
        $connection->table('fakturownia_attachment_workflows')->insertOrIgnore([
            'upload_operation_id' => $plan->uploadOperationId->value,
            'finalize_operation_id' => null,
            'connection_key' => $plan->connectionKey->value,
            'remote_id' => $result->remoteId,
            'resource_id' => $result->resourceId->value,
            'artifact_id' => $result->artifactId->value,
            'file_name' => $result->fileName,
            'disk' => $result->object->disk,
            'content_address' => (string) $result->object->contentAddress,
            'mime_type' => $result->object->mimeType,
            'size_bytes' => $result->object->sizeBytes,
            'expected_attachments_count' => $result->expectedAttachmentsCount,
            'revision_key_hmac_sha256' => $result->revisionKeyHmacSha256,
            'source_snapshot_hmac_sha256' => $result->sourceSnapshotHmacSha256,
            'uploaded_at' => $now,
            'finalize_accepted_at' => null,
            'finalized_at' => null,
        ]);

        $row = $connection->table('fakturownia_attachment_workflows')
            ->where('upload_operation_id', $plan->uploadOperationId->value)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof stdClass || ! $this->matches($row, $plan)) {
            throw new RuntimeException('The attachment upload projection conflicts with its durable workflow.');
        }
    }

    public function pendingFinalize(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new LogicException('Attachment finalize recovery limit must be between 1 and 100.');
        }

        return array_values($this->connection()->table('fakturownia_attachment_workflows')
            ->whereNull('finalize_operation_id')
            ->orderBy('uploaded_at')
            ->orderBy('upload_operation_id')
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $row): PendingAttachmentFinalize => $this->hydrate($row))
            ->all());
    }

    public function linkFinalize(OperationId $uploadOperationId, OperationId $finalizeOperationId): void
    {
        $connection = $this->connection();
        $row = $connection->table('fakturownia_attachment_workflows')
            ->where('upload_operation_id', $uploadOperationId->value)
            ->first();

        if (! $row instanceof stdClass) {
            throw new RuntimeException('The attachment upload workflow is missing.');
        }

        $current = $row->finalize_operation_id ?? null;

        if (is_string($current)) {
            if (! hash_equals($current, $finalizeOperationId->value)) {
                throw new RuntimeException('The attachment workflow is linked to another finalize operation.');
            }

            return;
        }

        $updated = $connection->table('fakturownia_attachment_workflows')
            ->where('upload_operation_id', $uploadOperationId->value)
            ->whereNull('finalize_operation_id')
            ->update([
                'finalize_operation_id' => $finalizeOperationId->value,
                'finalize_accepted_at' => $this->timestamp($this->clock->now()),
            ]);

        if ($updated !== 1) {
            $linked = $connection->table('fakturownia_attachment_workflows')
                ->where('upload_operation_id', $uploadOperationId->value)
                ->value('finalize_operation_id');

            if (! is_string($linked) || ! hash_equals($linked, $finalizeOperationId->value)) {
                throw new RuntimeException('The attachment workflow finalize link lost a conflicting race.');
            }
        }
    }

    public function markFinalized(OperationId $uploadOperationId, OperationId $finalizeOperationId): void
    {
        $connection = $this->connection();

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Attachment finalization must be projected inside the kernel terminal transaction.');
        }

        $row = $connection->table('fakturownia_attachment_workflows')
            ->where('upload_operation_id', $uploadOperationId->value)
            ->lockForUpdate()
            ->first();

        if (! $row instanceof stdClass) {
            throw new RuntimeException('The finalized attachment upload workflow is missing.');
        }

        $currentFinalize = $row->finalize_operation_id ?? null;

        if (is_string($currentFinalize) && ! hash_equals($currentFinalize, $finalizeOperationId->value)) {
            throw new RuntimeException('The attachment workflow finalized through another operation.');
        }

        if ($row->finalized_at !== null) {
            return;
        }

        $connection->table('fakturownia_attachment_workflows')
            ->where('upload_operation_id', $uploadOperationId->value)
            ->update([
                'finalize_operation_id' => $finalizeOperationId->value,
                'finalize_accepted_at' => $row->finalize_accepted_at ?? $this->timestamp($this->clock->now()),
                'finalized_at' => $this->timestamp($this->clock->now()),
            ]);
    }

    private function connection(): Connection
    {
        return $this->database->connection();
    }

    private function hydrate(stdClass $row): PendingAttachmentFinalize
    {
        return new PendingAttachmentFinalize(
            new ConnectionKey($this->string($row, 'connection_key')),
            new OperationId($this->string($row, 'upload_operation_id')),
            new InvoiceResourceId($this->string($row, 'resource_id')),
            $this->string($row, 'remote_id'),
            new ArtifactId($this->string($row, 'artifact_id')),
            $this->string($row, 'file_name'),
            new ArtifactObjectDescriptor(
                $this->string($row, 'disk'),
                ContentAddress::parse($this->string($row, 'content_address')),
                $this->string($row, 'mime_type'),
                $this->integer($row, 'size_bytes'),
            ),
            $this->integer($row, 'expected_attachments_count'),
            $this->string($row, 'revision_key_hmac_sha256'),
            $this->string($row, 'source_snapshot_hmac_sha256'),
        );
    }

    private function matches(stdClass $row, AttachmentUploadProjectionPlan $plan): bool
    {
        $result = $plan->result;

        return hash_equals($this->string($row, 'connection_key'), $plan->connectionKey->value)
            && hash_equals($this->string($row, 'remote_id'), $result->remoteId)
            && hash_equals($this->string($row, 'resource_id'), $result->resourceId->value)
            && hash_equals($this->string($row, 'artifact_id'), $result->artifactId->value)
            && hash_equals($this->string($row, 'file_name'), $result->fileName)
            && hash_equals($this->string($row, 'content_address'), (string) $result->object->contentAddress)
            && $this->integer($row, 'size_bytes') === $result->object->sizeBytes
            && hash_equals($this->string($row, 'revision_key_hmac_sha256'), $result->revisionKeyHmacSha256);
    }

    private function string(stdClass $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Attachment workflow {$column} is invalid.");
        }

        return $value;
    }

    private function integer(stdClass $row, string $column): int
    {
        $value = $row->{$column} ?? null;

        if (! is_int($value)) {
            throw new RuntimeException("Attachment workflow {$column} is invalid.");
        }

        return $value;
    }

    private function timestamp(DateTimeInterface $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }
}
