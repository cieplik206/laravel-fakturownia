<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

use Cieplik206\Fakturownia\Stateful\Ksef\Operations\EnsureAcceptedResult;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use InvalidArgumentException;
use JsonException;

final readonly class KsefInvoiceObservation
{
    use RejectsNativeSerialization;

    public function __construct(
        public string $remoteId,
        public OpenKsefStatus $status,
        public ?string $governmentId,
        public int $providerErrorCount = 0,
    ) {
        if ($remoteId === ''
            || $remoteId !== trim($remoteId)
            || strlen($remoteId) > 191
            || preg_match('//u', $remoteId) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $remoteId) === 1
            || $providerErrorCount < 0
            || $providerErrorCount > 10_000) {
            throw new InvalidArgumentException('The KSeF invoice observation contains invalid bounded metadata.');
        }

        if ($governmentId !== null
            && ($governmentId === ''
                || $governmentId !== trim($governmentId)
                || strlen($governmentId) > 256
                || preg_match('//u', $governmentId) !== 1
                || preg_match('/[\p{Cc}\p{Cf}]/u', $governmentId) === 1)) {
            throw new InvalidArgumentException('The observed KSeF government ID is invalid.');
        }
    }

    public function isAccepted(): bool
    {
        return $this->status->category() === KsefStatusCategory::Succeeded
            && $this->status->isTerminal($this->governmentId);
    }

    public function isRejected(): bool
    {
        return in_array(
            $this->status->category(),
            [KsefStatusCategory::Rejected, KsefStatusCategory::NotApplicable],
            true,
        );
    }

    public function provesSendStarted(): bool
    {
        return $this->status->category() !== KsefStatusCategory::NotSent
            || $this->governmentId !== null;
    }

    public function requiresConfigurationBlock(): bool
    {
        return $this->status->category() === KsefStatusCategory::ConfigurationBlocked;
    }

    public function isOffline(): bool
    {
        return $this->status->category() === KsefStatusCategory::Offline;
    }

    public function acceptedResult(): EnsureAcceptedResult
    {
        if (! $this->isAccepted() || $this->governmentId === null) {
            throw new InvalidArgumentException('A non-accepted KSeF observation has no accepted result.');
        }

        return new EnsureAcceptedResult(
            $this->remoteId,
            $this->status->raw,
            KsefTerminalOutcome::Accepted,
            $this->governmentId,
        );
    }

    public function rejectedResult(): EnsureAcceptedResult
    {
        $outcome = match ($this->status->category()) {
            KsefStatusCategory::Rejected => KsefTerminalOutcome::Rejected,
            KsefStatusCategory::NotApplicable => KsefTerminalOutcome::NotApplicable,
            default => throw new InvalidArgumentException('A non-rejected KSeF observation has no rejected result.'),
        };

        return new EnsureAcceptedResult($this->remoteId, $this->status->raw, $outcome, null);
    }

    public function canonical(bool $overdue = false): CanonicalObject
    {
        return new CanonicalObject([
            'remote_id' => $this->remoteId,
            'raw_status' => $this->status->raw,
            'status_category' => $this->status->category()->value,
            'government_id' => $this->governmentId,
            'provider_error_count' => $this->providerErrorCount,
            'offline' => $this->isOffline(),
            'configuration_blocked' => $this->requiresConfigurationBlock(),
            'overdue' => $overdue,
        ]);
    }

    public function fingerprint(bool $overdue = false): string
    {
        try {
            return hash('sha256', (new CanonicalJsonV1)->encode($this->canonical($overdue)));
        } catch (JsonException) {
            throw new InvalidArgumentException('The KSeF observation cannot be canonicalized.');
        }
    }
}
