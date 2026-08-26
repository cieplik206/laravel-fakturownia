<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Support\RemoteIdentifier;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;

final readonly class GetPaymentRequest extends JsonReadRequest
{
    public function __construct(string $paymentId)
    {
        $paymentId = RemoteIdentifier::assert($paymentId);

        parent::__construct(
            ReadCapability::PaymentGet->value,
            ReadCapability::PaymentGet,
            "/banking/payment/{$paymentId}.json",
            new QueryParameters,
        );
    }
}
