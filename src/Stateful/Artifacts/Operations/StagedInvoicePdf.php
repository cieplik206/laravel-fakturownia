<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Operations;

use Cieplik206\Fakturownia\Stateful\Artifacts\ContentAddress;
use Cieplik206\Fakturownia\Stateful\Artifacts\Contracts\ArtifactContentStream;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class StagedInvoicePdf
{
    use RejectsNativeSerialization;

    public const string MimeType = 'application/pdf';

    public function __construct(
        public ArtifactContentStream $content,
        public ContentAddress $contentAddress,
        public int $sizeBytes,
    ) {
        if ($sizeBytes < 9) {
            throw new InvalidArgumentException('A staged invoice PDF is too small.');
        }
    }
}
