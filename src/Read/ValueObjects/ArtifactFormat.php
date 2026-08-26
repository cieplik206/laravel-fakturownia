<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\ValueObjects;

enum ArtifactFormat: string
{
    case Pdf = 'pdf';
    case Zip = 'zip';
    case Xml = 'xml';
    case Upo = 'upo';

    /** @return non-empty-list<string> */
    public function acceptedContentTypes(): array
    {
        return match ($this) {
            self::Pdf => ['application/pdf'],
            self::Zip => ['application/zip', 'application/x-zip-compressed'],
            self::Xml, self::Upo => ['application/xml', 'text/xml'],
        };
    }
}
