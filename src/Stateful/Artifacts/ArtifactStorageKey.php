<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts;

final readonly class ArtifactStorageKey
{
    public static function for(ArtifactStorageNamespace $namespace, ContentAddress $contentAddress): string
    {
        $sha256 = $contentAddress->sha256();

        return $namespace->prefix.'/sha256/'.substr($sha256, 0, 2).'/'.$sha256;
    }
}
