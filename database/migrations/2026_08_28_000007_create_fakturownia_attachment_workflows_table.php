<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fakturownia_attachment_workflows', function (Blueprint $table): void {
            $table->char('upload_operation_id', 26)->primary();
            $table->char('finalize_operation_id', 26)->nullable()->unique();
            $table->string('connection_key', 128);
            $table->string('remote_id', 191);
            $table->char('resource_id', 26);
            $table->char('artifact_id', 26);
            $table->string('file_name', 191);
            $table->string('disk', 128);
            $table->string('content_address', 80);
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('expected_attachments_count');
            $table->char('revision_key_hmac_sha256', 64);
            $table->char('source_snapshot_hmac_sha256', 64);
            $table->timestampTz('uploaded_at', precision: 6);
            $table->timestampTz('finalize_accepted_at', precision: 6)->nullable();
            $table->timestampTz('finalized_at', precision: 6)->nullable();

            $table->unique(
                ['connection_key', 'resource_id', 'revision_key_hmac_sha256'],
                'fakturownia_attachment_workflows_resource_revision_unique',
            );
            $table->index(
                ['finalize_operation_id', 'uploaded_at'],
                'fakturownia_attachment_workflows_pending_finalize_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fakturownia_attachment_workflows');
    }

    public function getConnection(): ?string
    {
        $connection = config('integration-operations.database.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException('The Fakturownia attachment workflow database connection must be a string or null.');
        }

        return $connection;
    }
};
