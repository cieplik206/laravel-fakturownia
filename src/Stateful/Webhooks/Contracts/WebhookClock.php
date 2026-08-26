<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks\Contracts;

use DateTimeImmutable;

interface WebhookClock
{
    public function now(): DateTimeImmutable;
}
