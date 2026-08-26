<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Read\Requests;

use Cieplik206\Fakturownia\Read\Contracts\ReadRequestDescriptor;
use Cieplik206\Fakturownia\Read\ValueObjects\ArtifactFormat;
use Cieplik206\Fakturownia\Read\ValueObjects\QueryParameters;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadCapability;
use Cieplik206\Fakturownia\Read\ValueObjects\ReadSafety;
use Cieplik206\Fakturownia\Read\ValueObjects\RedirectPolicy;
use InvalidArgumentException;
use JsonException;

abstract readonly class StreamReadRequest implements ReadRequestDescriptor
{
    protected function __construct(
        private string $operationName,
        private ReadCapability $requiredCapability,
        private string $endpointPath,
        private QueryParameters $queryParameters,
        public ArtifactFormat $format,
        private int $responseByteLimit,
        public RedirectPolicy $redirectPolicy = RedirectPolicy::CrossHostWithoutCredentials,
        public int $maximumRedirects = 3,
        private ReadSafety $retrySafety = ReadSafety::Safe,
    ) {
        if ($operationName !== $requiredCapability->value) {
            throw new InvalidArgumentException('The stream operation must match its capability.');
        }

        if (preg_match('/^\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]+$/', $endpointPath) !== 1
            || str_contains($endpointPath, '//')
            || str_contains($endpointPath, '..')) {
            throw new InvalidArgumentException('The stream endpoint path is invalid.');
        }

        if ($responseByteLimit < 1 || $responseByteLimit > 52_428_800) {
            throw new InvalidArgumentException('The stream response byte limit is invalid.');
        }

        if ($maximumRedirects < 0 || $maximumRedirects > 5) {
            throw new InvalidArgumentException('The redirect limit must be between zero and five.');
        }

        if ($redirectPolicy === RedirectPolicy::Deny && $maximumRedirects !== 0) {
            throw new InvalidArgumentException('A request that denies redirects must use a zero redirect limit.');
        }
    }

    final public function operation(): string
    {
        return $this->operationName;
    }

    final public function capability(): ReadCapability
    {
        return $this->requiredCapability;
    }

    final public function path(): string
    {
        return $this->endpointPath;
    }

    final public function query(): QueryParameters
    {
        return $this->queryParameters;
    }

    final public function safety(): ReadSafety
    {
        return $this->retrySafety;
    }

    final public function maximumResponseBytes(): int
    {
        return $this->responseByteLimit;
    }

    /** @throws JsonException */
    final public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'class' => static::class,
            'operation' => $this->operationName,
            'capability' => $this->requiredCapability->value,
            'path' => $this->endpointPath,
            'query' => $this->queryParameters->all(),
            'format' => $this->format->value,
            'maximum_response_bytes' => $this->responseByteLimit,
            'redirect_policy' => $this->redirectPolicy->value,
            'maximum_redirects' => $this->maximumRedirects,
            'retry_safety' => $this->retrySafety->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
