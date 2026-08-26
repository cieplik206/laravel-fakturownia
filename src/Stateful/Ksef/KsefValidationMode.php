<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

enum KsefValidationMode: string
{
    case BlockInvalid = 'block_invalid';
    case PersistWithErrors = 'persist_with_errors';
}
