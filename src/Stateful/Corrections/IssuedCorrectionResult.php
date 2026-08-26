<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Corrections;

use Cieplik206\Fakturownia\Stateful\Invoices\Money;
use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class IssuedCorrectionResult
{
    use RejectsNativeSerialization;

    private const int MaximumExtraBytes = 1_048_576;

    /** @var array<string|int, mixed> */
    private array $extra;

    /** @param array<string|int, mixed> $extra */
    public function __construct(
        public string $remoteId,
        public string $sourceInvoiceId,
        public string $number,
        public string $status,
        public Money $totalGross,
        array $extra = [],
    ) {
        if (! self::validText($remoteId, 191)
            || ! self::validText($sourceInvoiceId, 191)
            || ! self::validText($status, 128)
            || ($number !== '' && ! self::validText($number, 191))) {
            throw new InvalidArgumentException('Issued correction identity and status must not be empty.');
        }

        $nodes = 0;
        $bytes = 0;
        $this->extra = self::validateExtra($extra, 0, $nodes, $bytes);
    }

    /** @return array<string|int, mixed> */
    public function extra(): array
    {
        return $this->extra;
    }

    private static function validText(string $value, int $maximumBytes): bool
    {
        return $value !== ''
            && $value === trim($value)
            && strlen($value) <= $maximumBytes
            && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string|int, mixed>
     */
    private static function validateExtra(
        array $values,
        int $depth,
        int &$nodes,
        int &$bytes,
    ): array {
        if ($depth > 8) {
            throw new InvalidArgumentException('The correction response extra payload is too deeply nested.');
        }

        $validated = [];
        foreach ($values as $key => $value) {
            $nodes++;
            if ($nodes > 2_000) {
                throw new InvalidArgumentException('The correction response extra payload is too large.');
            }

            if (is_string($key)) {
                if ($key === ''
                    || strlen($key) > 191
                    || preg_match('//u', $key) !== 1
                    || preg_match('/[\p{Cc}\p{Cf}]/u', $key) === 1
                    || preg_match('/(?:token|authorization|password|secret|credential)/i', $key) === 1) {
                    throw new InvalidArgumentException('The correction response extra payload contains an invalid key.');
                }

                $bytes += strlen($key);

                if ($bytes > self::MaximumExtraBytes) {
                    throw new InvalidArgumentException('The correction response extra payload exceeds the byte limit.');
                }
            }

            if (is_array($value)) {
                $validated[$key] = self::validateExtra($value, $depth + 1, $nodes, $bytes);

                continue;
            }

            if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
                throw new InvalidArgumentException('The correction response extra payload contains a mutable value.');
            }

            if (is_string($value)) {
                $bytes += strlen($value);

                if (strlen($value) > 65_536
                    || preg_match('//u', $value) !== 1
                    || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
                    throw new InvalidArgumentException('The correction response extra payload contains an invalid string.');
                }
            }

            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidArgumentException('The correction response extra payload contains a non-finite number.');
            }

            if ($bytes > self::MaximumExtraBytes) {
                throw new InvalidArgumentException('The correction response extra payload exceeds the byte limit.');
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    /** @return array{remote_id: string, source_invoice_id: string, number: string, status: string, extra: string} */
    public function __debugInfo(): array
    {
        return [
            'remote_id' => $this->remoteId,
            'source_invoice_id' => $this->sourceInvoiceId,
            'number' => $this->number,
            'status' => $this->status,
            'extra' => '[REDACTED]',
        ];
    }
}
