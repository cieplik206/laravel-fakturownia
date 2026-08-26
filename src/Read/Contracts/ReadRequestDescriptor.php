<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Contracts;

use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadSafety;

interface ReadRequestDescriptor
{
    public function operation(): string;

    public function capability(): ReadCapability;

    public function path(): string;

    public function query(): QueryParameters;

    public function safety(): ReadSafety;

    public function maximumResponseBytes(): int;

    public function fingerprint(): string;
}
