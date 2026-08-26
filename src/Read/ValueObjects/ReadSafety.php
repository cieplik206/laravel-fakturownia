<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\ValueObjects;

enum ReadSafety: string
{
    case Safe = 'read_safe';
    case NeverRetry = 'never_retry';
}
