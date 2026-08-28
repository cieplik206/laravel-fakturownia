<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments;

use Cieplik206\Fakturownia\Stateful\Artifacts\ArtifactObjectDescriptor;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class StagedAttachmentSource
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $fileName,
        public ArtifactObjectDescriptor $object,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.pdf$/D', $fileName) !== 1
            || ! hash_equals($object->mimeType, 'application/pdf')
            || $object->sizeBytes < 9
            || $object->sizeBytes > 20 * 1_048_576) {
            throw new InvalidArgumentException('The staged attachment source is invalid.');
        }
    }
}
