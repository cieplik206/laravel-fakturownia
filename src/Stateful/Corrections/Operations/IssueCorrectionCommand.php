<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Stateful\Corrections\CorrectionDraft;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;

final readonly class IssueCorrectionCommand
{
    use RejectsNativeSerialization;

    public function __construct(public CorrectionDraft $draft) {}
}
