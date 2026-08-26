<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Support;

use Cieplik206\Fakturownia\Read\Data\ApiDate;
use Cieplik206\Fakturownia\Read\Data\ApiMonth;
use Cieplik206\Fakturownia\Read\Data\ApiTimestamp;
use Cieplik206\Fakturownia\Read\Data\DecimalValue;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use InvalidArgumentException;

/** @internal */
final readonly class PayloadReader
{
    /** @var array<string, mixed> */
    private array $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(array $payload, private string $operation)
    {
        try {
            $sanitized = PayloadSanitizer::sanitizeArray($payload);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation($operation, 'resource payload');
        }
        $normalized = [];

        foreach ($sanitized as $key => $value) {
            if (! is_string($key)) {
                throw new ProtocolViolation($operation, 'resource map key');
            }

            $normalized[$key] = $value;
        }

        $this->payload = $normalized;
    }

    public function requiredId(string $key): string
    {
        $identifier = $this->nullableId($key);

        if ($identifier === null) {
            throw new ProtocolViolation($this->operation, "required {$key} field");
        }

        return $identifier;
    }

    public function nullableId(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new ProtocolViolation($this->operation, "{$key} identifier field");
        }

        $identifier = (string) $value;

        if (preg_match('/^[1-9][0-9]{0,39}$/', $identifier) !== 1) {
            throw new ProtocolViolation($this->operation, "{$key} identifier field");
        }

        return $identifier;
    }

    public function nullableString(string $key, bool $blankIsNull = true): ?string
    {
        $value = $this->payload[$key] ?? null;

        if ($value === null || ($blankIsNull && $value === '')) {
            return null;
        }

        if (! is_string($value)) {
            throw new ProtocolViolation($this->operation, "{$key} string field");
        }

        return $value;
    }

    public function nullableScalarString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            try {
                return DecimalValue::from($value)->value;
            } catch (InvalidArgumentException) {
                throw new ProtocolViolation($this->operation, "{$key} scalar field");
            }
        }

        if (! is_string($value)) {
            throw new ProtocolViolation($this->operation, "{$key} scalar field");
        }

        return $value;
    }

    public function nullableExactScalarString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value) || (! is_int($value) && ! is_string($value))) {
            throw new ProtocolViolation($this->operation, "{$key} exact scalar provenance");
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return $value;
    }

    public function nullableDecimal(string $key): ?DecimalValue
    {
        $value = $this->payload[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new ProtocolViolation($this->operation, "{$key} decimal field");
        }

        try {
            return DecimalValue::from($value);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation($this->operation, "{$key} decimal field");
        }
    }

    public function nullableExactDecimal(string $key): ?DecimalValue
    {
        $value = $this->payload[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value) || (! is_int($value) && ! is_string($value))) {
            throw new ProtocolViolation($this->operation, "{$key} exact decimal provenance");
        }

        try {
            return DecimalValue::from($value);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation($this->operation, "{$key} exact decimal field");
        }
    }

    public function nullableBoolean(string $key): ?bool
    {
        $value = $this->payload[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'no') {
            return false;
        }

        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'yes') {
            return true;
        }

        throw new ProtocolViolation($this->operation, "{$key} boolean field");
    }

    public function nullableDate(string $key): ?ApiDate
    {
        $value = $this->nullableString($key);

        if ($value === null) {
            return null;
        }

        try {
            return new ApiDate($value);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation($this->operation, "{$key} date field");
        }
    }

    public function nullableTimestamp(string $key): ?ApiTimestamp
    {
        $value = $this->nullableString($key);

        if ($value === null) {
            return null;
        }

        try {
            return new ApiTimestamp($value);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation($this->operation, "{$key} timestamp field");
        }
    }

    public function nullableDateOrMonth(string $key): ApiDate|ApiMonth|null
    {
        $value = $this->nullableString($key);

        if ($value === null) {
            return null;
        }

        try {
            return strlen($value) === 7 ? new ApiMonth($value) : new ApiDate($value);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation($this->operation, "{$key} date or month field");
        }
    }

    public function nullableDateOrTimestamp(string $key): ApiDate|ApiTimestamp|null
    {
        $value = $this->nullableString($key);

        if ($value === null) {
            return null;
        }

        try {
            return strlen($value) === 10 ? new ApiDate($value) : new ApiTimestamp($value);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation($this->operation, "{$key} date or timestamp field");
        }
    }

    /** @return list<array<string, mixed>> */
    public function objectList(string $key): array
    {
        $value = $this->payload[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new ProtocolViolation($this->operation, "{$key} list field");
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new ProtocolViolation($this->operation, "{$key} item shape");
            }

            $normalized = [];

            foreach ($item as $itemKey => $itemValue) {
                if (! is_string($itemKey)) {
                    throw new ProtocolViolation($this->operation, "{$key} item map key");
                }

                $normalized[$itemKey] = $itemValue;
            }

            $items[] = $normalized;
        }

        return $items;
    }

    /**
     * @param  list<string>  $knownKeys
     * @return array<string, mixed>
     */
    public function extra(array $knownKeys): array
    {
        return array_diff_key($this->payload, array_fill_keys($knownKeys, true));
    }
}
