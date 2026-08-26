<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Invoices;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

final readonly class IssuedInvoiceResult
{
    private const int MaximumExtraBytes = 1_048_576;

    private const int MaximumExtraDepth = 8;

    private const int MaximumExtraNodes = 2000;

    private const int MaximumPositions = 1000;

    /** @var list<InvoiceLine> */
    public array $positions;

    /** @var array<string|int, mixed> */
    private array $extra;

    /**
     * @param  array<mixed>  $positions
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $remoteId,
        public string $number,
        public string $kind,
        public string $status,
        public string $issueDate,
        public ?string $buyerTaxNumber,
        public Money $totalGross,
        public ?string $oid,
        array $positions,
        array $extra = [],
    ) {
        if (! self::validText($remoteId, 191)
            || ! self::validOptionalText($number, 191, true)
            || ! self::validText($kind, 64)
            || ! self::validText($status, 128)
            || ! self::validDate($issueDate)
            || ! self::validOptionalText($buyerTaxNumber, 256)
            || ! self::validOptionalText($oid, 256)) {
            throw new InvalidArgumentException('Issued invoice identity and text fields are invalid.');
        }

        if ($positions === [] || count($positions) > self::MaximumPositions) {
            throw new InvalidArgumentException('Issued invoice positions must be a bounded non-empty list.');
        }

        foreach ($positions as $position) {
            if (! $position instanceof InvoiceLine) {
                throw new InvalidArgumentException('Issued invoice positions must contain only invoice lines.');
            }
        }

        $this->positions = array_values($positions);
        $nodes = 0;
        $bytes = 0;
        $this->extra = self::validateExtra($extra, 0, $nodes, $bytes);
    }

    /** @return array<string|int, mixed> */
    public function extra(): array
    {
        return $this->extra;
    }

    /** @return array{remote_id: string, number: string, kind: string, status: string, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'remote_id' => $this->remoteId,
            'number' => $this->number,
            'kind' => $this->kind,
            'status' => $this->status,
            'credentials' => '[REDACTED]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Issued invoice results cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Issued invoice results cannot be unserialized.');
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $values
     * @return array<TKey, mixed>
     */
    private static function validateExtra(
        array $values,
        int $depth,
        int &$nodes,
        int &$bytes,
    ): array {
        if ($depth > self::MaximumExtraDepth) {
            throw new InvalidArgumentException('Issued invoice response extra payload is too deeply nested.');
        }

        $validated = [];

        foreach ($values as $key => $value) {
            $nodes++;
            if ($nodes > self::MaximumExtraNodes) {
                throw new InvalidArgumentException('Issued invoice response extra payload contains too many values.');
            }

            if (is_string($key)) {
                self::assertExtraKey($key);
                $bytes += strlen($key);
            }

            if (is_array($value)) {
                $validated[$key] = self::validateExtra($value, $depth + 1, $nodes, $bytes);

                continue;
            }

            if (! is_string($value)
                && ! is_int($value)
                && ! is_float($value)
                && ! is_bool($value)
                && $value !== null) {
                throw new InvalidArgumentException('Issued invoice response extra payload contains a mutable value.');
            }

            if (is_string($value)) {
                $bytes += strlen($value);

                if (preg_match('//u', $value) !== 1
                    || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
                    throw new InvalidArgumentException('Issued invoice response extra payload contains invalid text.');
                }
            }

            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException('Issued invoice response extra payload contains a non-finite number.');
            }

            if ($bytes > self::MaximumExtraBytes) {
                throw new InvalidArgumentException('Issued invoice response extra payload exceeds the byte limit.');
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    private static function assertExtraKey(string $key): void
    {
        if ($key === ''
            || strlen($key) > 256
            || preg_match('//u', $key) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $key) === 1
            || preg_match('/(?:api[_-]?token|access[_-]?token|authorization|password|secret|credential)/i', $key) === 1) {
            throw new InvalidArgumentException('Issued invoice response extra payload contains an invalid or reserved key.');
        }
    }

    private static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private static function validOptionalText(
        ?string $value,
        int $maximumBytes,
        bool $allowsEmpty = false,
    ): bool {
        return $value === null
            || ($allowsEmpty && $value === '')
            || self::validText($value, $maximumBytes);
    }

    private static function validText(string $value, int $maximumBytes): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= $maximumBytes
            && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }
}
