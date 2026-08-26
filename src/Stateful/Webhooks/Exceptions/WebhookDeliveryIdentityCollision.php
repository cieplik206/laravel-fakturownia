<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Webhooks\Exceptions;

use RuntimeException;

final class WebhookDeliveryIdentityCollision extends RuntimeException {}
