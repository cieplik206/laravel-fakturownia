<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use Cieplik206\Fakturownia\Stateful\Invoices\Exceptions\InvoiceDraftInvalid;
use InvalidArgumentException;

final readonly class InvoiceDraftValidationResult
{
    /** @var list<InvoiceDraftValidationIssue> */
    public array $issues;

    /** @param array<mixed> $issues */
    public function __construct(array $issues)
    {
        foreach ($issues as $issue) {
            if (! $issue instanceof InvoiceDraftValidationIssue) {
                throw new InvalidArgumentException('Invoice validation result contains an invalid issue.');
            }
        }

        $this->issues = array_values($issues);
    }

    public function isValid(): bool
    {
        return $this->issues === [];
    }

    public function throwIfInvalid(): void
    {
        if (! $this->isValid()) {
            throw new InvoiceDraftInvalid(count($this->issues));
        }
    }
}
