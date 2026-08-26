<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Data;

use InvalidArgumentException;
use JsonSerializable;

final readonly class OpenInvoiceKind implements JsonSerializable
{
    public function __construct(public string $raw)
    {
        if ($raw === '' || strlen($raw) > 128 || preg_match('//u', $raw) !== 1) {
            throw new InvalidArgumentException('The remote invoice kind is invalid.');
        }
    }

    public function known(): ?KnownInvoiceKind
    {
        return KnownInvoiceKind::tryFrom($this->raw);
    }

    public function jsonSerialize(): string
    {
        return $this->raw;
    }
}
