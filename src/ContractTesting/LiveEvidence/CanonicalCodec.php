<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

final class CanonicalCodec
{
    private const MaximumDepth = 64;

    private const MaximumNodes = 500_000;

    private const MaximumStringBytes = 1_048_576;

    private const MaximumDocumentBytes = 16_777_216;

    /** @param array<array-key, mixed> $document */
    public static function encode(#[SensitiveParameter] array $document): string
    {
        if (\array_is_list($document)) {
            throw new InvalidArgumentException('A canonical live-evidence document must be a JSON object.');
        }

        $nodes = 0;

        try {
            $encoded = \json_encode(
                self::canonicalize($document, 0, $nodes),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The live-evidence document cannot be encoded canonically.', previous: $exception);
        }

        if (\strlen($encoded) > self::MaximumDocumentBytes) {
            throw new InvalidArgumentException('The canonical live-evidence document exceeds the size limit.');
        }

        return $encoded;
    }

    private static function canonicalize(#[SensitiveParameter] mixed $value, int $depth, int &$nodes): mixed
    {
        $nodes++;

        if ($nodes > self::MaximumNodes || $depth > self::MaximumDepth) {
            throw new InvalidArgumentException('The canonical live-evidence document exceeds structural limits.');
        }

        if (\is_array($value)) {
            if (\array_is_list($value)) {
                $result = [];

                foreach ($value as $item) {
                    $result[] = self::canonicalize($item, $depth + 1, $nodes);
                }

                return $result;
            }

            foreach (\array_keys($value) as $key) {
                if (! \is_string($key)
                    || $key === ''
                    || \strlen($key) > self::MaximumStringBytes
                    || \preg_match('//u', $key) !== 1) {
                    throw new InvalidArgumentException('Canonical live-evidence objects require non-empty string keys.');
                }
            }

            \ksort($value, \SORT_STRING);

            foreach ($value as $key => $item) {
                $value[$key] = self::canonicalize($item, $depth + 1, $nodes);
            }

            return $value;
        }

        if (\is_string($value)) {
            if (\strlen($value) > self::MaximumStringBytes || \preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException('Canonical live-evidence strings must be bounded valid UTF-8.');
            }

            return $value;
        }

        if (\is_int($value) || \is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('Canonical live-evidence documents may contain only JSON scalars and arrays, without floats.');
    }
}
