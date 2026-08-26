<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef\Operations;

use Cieplik206\Fakturownia\Stateful\Ksef\KsefTerminalOutcome;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use InvalidArgumentException;

final readonly class EnsureAcceptedResult implements OperationResult
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $remoteId,
        public string $rawStatus,
        public KsefTerminalOutcome $outcome,
        public ?string $governmentId,
    ) {
        if ($remoteId === '' || $rawStatus === '' || strlen($remoteId) > 191 || strlen($rawStatus) > 128) {
            throw new InvalidArgumentException('The KSeF operation result contains invalid remote identity metadata.');
        }

        if (($outcome === KsefTerminalOutcome::Accepted) !== ($governmentId !== null)) {
            throw new InvalidArgumentException('Only an accepted KSeF result may contain a government ID.');
        }

        if ($governmentId !== null
            && ($governmentId === ''
                || $governmentId !== trim($governmentId)
                || strlen($governmentId) > 256
                || preg_match('//u', $governmentId) !== 1
                || preg_match('/[\p{Cc}\p{Cf}]/u', $governmentId) === 1)) {
            throw new InvalidArgumentException('The KSeF result government ID is invalid.');
        }
    }

    public function resultType(): string
    {
        return EnsureAcceptedResultCodec::resultType();
    }
}
