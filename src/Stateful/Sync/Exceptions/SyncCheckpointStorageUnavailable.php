<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Sync\Exceptions;

use RuntimeException;

final class SyncCheckpointStorageUnavailable extends RuntimeException {}
