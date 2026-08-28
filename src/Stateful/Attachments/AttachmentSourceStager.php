<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments;

use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ContentAddressedArtifactStore;
use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\InvoicePdfStager;
use RuntimeException;

final readonly class AttachmentSourceStager
{
    public const int MaximumBytes = 20 * 1_048_576;

    public function __construct(
        private InvoicePdfStager $pdfs,
        private ContentAddressedArtifactStore $objects,
    ) {}

    public function stage(ArtifactContentStream $source, string $fileName): StagedAttachmentSource
    {
        $staged = $this->pdfs->stage($source, self::MaximumBytes);

        try {
            $object = $this->objects->put($staged->content, 'application/pdf');
        } finally {
            $staged->content->close();
        }

        if (! $object->contentAddress->equals($staged->contentAddress)
            || $object->sizeBytes !== $staged->sizeBytes) {
            throw new RuntimeException('The durable attachment source conflicts with its staged content.');
        }

        return new StagedAttachmentSource($fileName, $object);
    }
}
