<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

enum CorrectionPositionKind: string
{
    case Before = 'correction_before';
    case After = 'correction_after';
}
