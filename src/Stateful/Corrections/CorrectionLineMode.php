<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

enum CorrectionLineMode: string
{
    case Quantity = 'quantity';
    case Value = 'value';
    case Preserved = 'preserved';
}
