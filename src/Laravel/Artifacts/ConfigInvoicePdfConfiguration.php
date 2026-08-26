<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Artifacts;

use Cieplik206\Fakturownia\Stateful\Artifacts\Operations\Contracts\InvoicePdfConfiguration;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final readonly class ConfigInvoicePdfConfiguration implements InvoicePdfConfiguration
{
    public function __construct(private Repository $configuration) {}

    public function maximumBytes(): int
    {
        $maximumBytes = $this->configuration->get('fakturownia.artifacts.max_pdf_bytes');

        if (! is_int($maximumBytes) || $maximumBytes < 9 || $maximumBytes > 100 * 1_048_576) {
            throw new InvalidArgumentException('The invoice PDF maximum byte limit is invalid.');
        }

        return $maximumBytes;
    }
}
