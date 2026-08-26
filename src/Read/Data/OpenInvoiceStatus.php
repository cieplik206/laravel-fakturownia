<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use InvalidArgumentException;
use JsonSerializable;

final readonly class OpenInvoiceStatus implements JsonSerializable
{
    public function __construct(public string $raw)
    {
        if ($raw === '' || strlen($raw) > 128 || preg_match('//u', $raw) !== 1) {
            throw new InvalidArgumentException('The remote invoice status is invalid.');
        }
    }

    public function known(): ?KnownInvoiceStatus
    {
        return KnownInvoiceStatus::tryFrom($this->raw);
    }

    public function jsonSerialize(): string
    {
        return $this->raw;
    }
}
