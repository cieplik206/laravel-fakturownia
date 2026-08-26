<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Ksef;

use InvalidArgumentException;
use JsonSerializable;

final readonly class OpenKsefStatus implements JsonSerializable
{
    public function __construct(public string $raw)
    {
        if ($raw === ''
            || $raw !== trim($raw)
            || strlen($raw) > 128
            || preg_match('/\A[a-z0-9][a-z0-9._:-]*\z/', $raw) !== 1) {
            throw new InvalidArgumentException('The remote KSeF status is invalid.');
        }
    }

    public function known(): ?KnownKsefStatus
    {
        return KnownKsefStatus::tryFrom($this->raw);
    }

    public function category(): KsefStatusCategory
    {
        return match ($this->known()) {
            KnownKsefStatus::NotSent => KsefStatusCategory::NotSent,
            KnownKsefStatus::Ok, KnownKsefStatus::DemoOk => KsefStatusCategory::Succeeded,
            KnownKsefStatus::Processing, KnownKsefStatus::DemoProcessing => KsefStatusCategory::Processing,
            KnownKsefStatus::StatusCheckError => KsefStatusCategory::StatusCheckError,
            KnownKsefStatus::ServerError,
            KnownKsefStatus::DemoServerError,
            KnownKsefStatus::SendError,
            KnownKsefStatus::DemoSendError => KsefStatusCategory::TechnicalError,
            KnownKsefStatus::Offline, KnownKsefStatus::OfflineError, KnownKsefStatus::Offline24 => KsefStatusCategory::Offline,
            KnownKsefStatus::DuplicateError => KsefStatusCategory::Duplicate,
            KnownKsefStatus::Blocked403Error,
            KnownKsefStatus::NotConnected,
            KnownKsefStatus::DemoNotConnected => KsefStatusCategory::ConfigurationBlocked,
            KnownKsefStatus::NotApplicable,
            KnownKsefStatus::DemoNotApplicable => KsefStatusCategory::NotApplicable,
            KnownKsefStatus::Rejected, KnownKsefStatus::DemoRejected => KsefStatusCategory::Rejected,
            null => KsefStatusCategory::Unknown,
        };
    }

    public function isTerminal(?string $governmentId = null): bool
    {
        if ($this->category() !== KsefStatusCategory::Succeeded) {
            return $this->category()->isTerminal();
        }

        return is_string($governmentId)
            && $governmentId !== ''
            && $governmentId === trim($governmentId)
            && strlen($governmentId) <= 256
            && preg_match('//u', $governmentId) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $governmentId) !== 1;
    }

    public function jsonSerialize(): string
    {
        return $this->raw;
    }
}
