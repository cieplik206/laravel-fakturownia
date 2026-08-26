<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;

final readonly class DisabledContentAddressedArtifactStore implements ContentAddressedArtifactStore
{
    public function put(ArtifactContentStream $content, string $mimeType): ArtifactObjectDescriptor
    {
        throw DownloadInvoicePdfOperationFailure::capabilityUnavailable();
    }

    public function inspect(ContentAddress $contentAddress): ?ArtifactObjectDescriptor
    {
        throw DownloadInvoicePdfOperationFailure::capabilityUnavailable();
    }

    public function open(ContentAddress $contentAddress): ArtifactContentStream
    {
        throw DownloadInvoicePdfOperationFailure::capabilityUnavailable();
    }
}
