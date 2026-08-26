<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Exceptions;

enum ConnectionConfigurationReason: string
{
    case NotConfigured = 'not_configured';
    case InvalidShape = 'invalid_shape';
    case InvalidValue = 'invalid_value';
    case ResolvedKeyMismatch = 'resolved_key_mismatch';
}
