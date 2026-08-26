<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Shadow;

enum ShadowDifferenceKind: string
{
    case ValueMismatch = 'value_mismatch';
    case PositionCountMismatch = 'position_count_mismatch';
    case PositionSetMismatch = 'position_set_mismatch';
}
