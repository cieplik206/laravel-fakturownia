<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections\Operations;

use Cieplik206\Fakturownia\Stateful\Corrections\IssuedCorrectionResult;
use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;

final readonly class IssueCorrectionResult implements OperationResult
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $remoteId,
        public string $sourceInvoiceId,
        public string $number,
        public string $status,
        public Money $totalGross,
    ) {
        new IssuedCorrectionResult(
            $remoteId,
            $sourceInvoiceId,
            $number,
            $status,
            $totalGross,
        );
    }

    public static function fromIssuedCorrectionResult(IssuedCorrectionResult $correction): self
    {
        return new self(
            $correction->remoteId,
            $correction->sourceInvoiceId,
            $correction->number,
            $correction->status,
            $correction->totalGross,
        );
    }

    public function resultType(): string
    {
        return IssueCorrectionResultCodec::resultType();
    }

    public function toIssuedCorrectionResult(): IssuedCorrectionResult
    {
        return new IssuedCorrectionResult(
            $this->remoteId,
            $this->sourceInvoiceId,
            $this->number,
            $this->status,
            $this->totalGross,
        );
    }
}
