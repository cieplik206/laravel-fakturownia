<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Support;

use Cieplik206\Fakturownia\Read\Contracts\ReadClock;
use Cieplik206\Fakturownia\Read\Exceptions\ProtocolViolation;
use Cieplik206\Fakturownia\Read\Exceptions\RemoteErrorEnvelope;
use Cieplik206\Fakturownia\Read\Requests\JsonReadRequest;
use Cieplik206\Fakturownia\Read\Responses\JsonReadResponse;
use InvalidArgumentException;
use JsonException;

/** @internal */
final readonly class ResponseDecoder
{
    private ResponseStatusClassifier $statusClassifier;

    public function __construct(ReadClock $clock)
    {
        $this->statusClassifier = new ResponseStatusClassifier($clock);
    }

    /** @return array<string, mixed> */
    public function object(JsonReadRequest $request, JsonReadResponse $response): array
    {
        $decoded = $this->decode($request, $response);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new ProtocolViolation(
                $request->operation(),
                'JSON object shape',
                $response->statusCode,
                $response->headers->providerRequestId(),
            );
        }

        $this->assertNoErrorEnvelope($request, $response, $decoded);

        return $this->sanitize($request, $response, $decoded);
    }

    /** @return list<array<string, mixed>> */
    public function list(JsonReadRequest $request, JsonReadResponse $response): array
    {
        $decoded = $this->decode($request, $response);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            if (is_array($decoded)) {
                $this->assertNoErrorEnvelope(
                    $request,
                    $response,
                    $this->sanitize($request, $response, $decoded),
                );
            }

            throw new ProtocolViolation(
                $request->operation(),
                'JSON list shape',
                $response->statusCode,
                $response->headers->providerRequestId(),
            );
        }

        $items = [];

        foreach ($decoded as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new ProtocolViolation(
                    $request->operation(),
                    'JSON resource item shape',
                    $response->statusCode,
                    $response->headers->providerRequestId(),
                );
            }

            $items[] = $this->sanitize($request, $response, $item);
        }

        return $items;
    }

    private function decode(JsonReadRequest $request, JsonReadResponse $response): mixed
    {
        $this->statusClassifier->assertSuccessful($request, $response->statusCode, $response->headers);
        $contentTypes = $response->headers->values('content-type');

        if (count($contentTypes) !== 1) {
            throw new ProtocolViolation(
                $request->operation(),
                'single JSON content type',
                $response->statusCode,
                $response->headers->providerRequestId(),
            );
        }

        $contentType = strtolower(trim(explode(';', $contentTypes[0])[0]));

        if ($contentType !== 'application/json' && ! str_ends_with($contentType, '+json')) {
            throw new ProtocolViolation(
                $request->operation(),
                'JSON content type',
                $response->statusCode,
                $response->headers->providerRequestId(),
            );
        }

        $body = $response->body($request);
        $contentLengths = $response->headers->values('content-length');

        if (count($contentLengths) > 1) {
            throw new ProtocolViolation(
                $request->operation(),
                'single JSON content length',
                $response->statusCode,
                $response->headers->providerRequestId(),
            );
        }

        $contentLength = $contentLengths[0] ?? null;

        if ($contentLength !== null
            && (preg_match('/^[0-9]{1,16}$/', $contentLength) !== 1 || (int) $contentLength !== strlen($body))) {
            throw new ProtocolViolation(
                $request->operation(),
                'JSON content length',
                $response->statusCode,
                $response->headers->providerRequestId(),
            );
        }

        try {
            return json_decode($body, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ProtocolViolation(
                $request->operation(),
                'JSON decoding',
                $response->statusCode,
                $response->headers->providerRequestId(),
            );
        }
    }

    /** @param array<string, mixed> $decoded */
    private function assertNoErrorEnvelope(
        JsonReadRequest $request,
        JsonReadResponse $response,
        array $decoded,
    ): void {
        $code = $decoded['code'] ?? null;

        if ($code === null || $code === 'success') {
            return;
        }

        $safeCode = is_string($code) && preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $code) === 1 ? $code : null;

        throw new RemoteErrorEnvelope(
            $request->operation(),
            $safeCode,
            $response->headers->providerRequestId(),
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitize(JsonReadRequest $request, JsonReadResponse $response, array $payload): array
    {
        try {
            $sanitized = PayloadSanitizer::sanitizeArray($payload);
        } catch (InvalidArgumentException) {
            throw new ProtocolViolation(
                $request->operation(),
                'JSON value graph',
                $response->statusCode,
                $response->headers->providerRequestId(),
            );
        }

        $normalized = [];

        foreach ($sanitized as $key => $value) {
            if (! is_string($key)) {
                throw new ProtocolViolation(
                    $request->operation(),
                    'JSON object map key',
                    $response->statusCode,
                    $response->headers->providerRequestId(),
                );
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
