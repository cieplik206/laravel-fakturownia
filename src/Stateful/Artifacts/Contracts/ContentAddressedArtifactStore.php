<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Contracts;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;

interface ContentAddressedArtifactStore
{
    /**
     * The store calculates SHA-256 from the consumed bytes and derives the canonical address. Existing bytes must never be overwritten. Repeating
     * the same content is idempotent, while an existing object with inconsistent metadata is an integrity failure. A successful return guarantees
     * that the complete object exists and matches the descriptor.
     *
     * This object-store write is an independent consistency boundary. It must not claim atomicity with a database transaction, and a later database
     * rollback may intentionally leave an orphan for the retention sweeper.
     */
    public function put(ArtifactContentStream $content, string $mimeType): ArtifactObjectDescriptor;

    public function inspect(ContentAddress $contentAddress): ?ArtifactObjectDescriptor;

    public function open(ContentAddress $contentAddress): ArtifactContentStream;
}
