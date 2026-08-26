<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks\Contracts;

use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookInboxEntry;
use Cieplik206\Fakturownia\Stateful\Webhooks\WebhookInboxReceipt;

interface WebhookInboxRepository
{
    public function store(WebhookInboxEntry $entry, int $fallbackDeduplicationWindowSeconds): WebhookInboxReceipt;
}
