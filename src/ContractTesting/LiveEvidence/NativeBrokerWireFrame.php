<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

final class NativeBrokerWireFrame
{
    public const MaximumPayloadBytes = 2_097_152;

    public const HeaderBytes = 9;

    private function __construct() {}

    /** @param array<string, mixed> $document */
    public static function encode(#[SensitiveParameter] array $document): string
    {
        if ($document === []) {
            throw new InvalidArgumentException('A native broker wire frame must contain one JSON object.');
        }

        $payload = CanonicalCodec::encode($document);
        $length = \strlen($payload);

        if ($length > self::MaximumPayloadBytes) {
            throw new InvalidArgumentException('The native broker wire frame exceeds the payload limit.');
        }

        return \sprintf('%08x', $length)."\n".$payload;
    }

    /** @return array<string, mixed> */
    public static function decode(#[SensitiveParameter] string $frame): array
    {
        if (\strlen($frame) < self::HeaderBytes
            || \preg_match('/^[a-f0-9]{8}\n/D', $frame) !== 1) {
            throw new InvalidArgumentException('The native broker wire frame header is invalid.');
        }

        $declaredLength = \hexdec(\substr($frame, 0, 8));
        $payload = \substr($frame, self::HeaderBytes);

        if ($declaredLength > self::MaximumPayloadBytes
            || $declaredLength !== \strlen($payload)) {
            throw new InvalidArgumentException('The native broker wire frame length binding is invalid.');
        }

        try {
            $document = \json_decode($payload, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The native broker wire frame payload is invalid JSON.', 0, $exception);
        }

        if (! \is_array($document)
            || $document === []
            || \array_is_list($document)
            || ! \hash_equals(CanonicalCodec::encode($document), $payload)) {
            throw new InvalidArgumentException('The native broker wire frame payload is not one canonical JSON object.');
        }

        return $document;
    }
}
