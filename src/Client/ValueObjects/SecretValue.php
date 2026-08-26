<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Client\ValueObjects;

use Cieplik206\Fakturownia\Client\FakturowniaClient;
use Cieplik206\Fakturownia\ContractTesting\LiveEvidence\SaloonRuntimeIsolationGuard;
use Closure;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Saloon\Contracts\Authenticator;
use Saloon\Contracts\Sender;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Senders\GuzzleSender;
use SensitiveParameter;
use SensitiveParameterValue;

final class SecretValue implements JsonSerializable
{
    private const Redacted = '[REDACTED]';

    private SensitiveParameterValue $plaintext;

    private function __construct(#[SensitiveParameter] string $plaintext)
    {
        $this->plaintext = new SensitiveParameterValue($plaintext);
    }

    public static function fromPlaintext(#[SensitiveParameter] string $plaintext): self
    {
        if ($plaintext === '' || trim($plaintext) !== $plaintext) {
            throw new InvalidArgumentException('The API token must be a non-empty string without surrounding whitespace.');
        }

        if (strlen($plaintext) > 4096) {
            throw new InvalidArgumentException('The API token is unexpectedly long.');
        }

        return new self($plaintext);
    }

    public function createClient(
        #[SensitiveParameter] BaseUrl $baseUrl,
        int $connectTimeoutSeconds,
        int $requestTimeoutSeconds,
    ): FakturowniaClient {
        SaloonRuntimeIsolationGuard::assertIsolated();

        $authenticate = function (PendingRequest $pendingRequest): void {
            $plaintext = $this->plaintext->getValue();

            if (! is_string($plaintext)) {
                throw new LogicException('The secret value is corrupted.');
            }

            $pendingRequest->query()->add('api_token', $plaintext);
        };

        $connector = new class($baseUrl, $connectTimeoutSeconds, $requestTimeoutSeconds, $authenticate, new GuzzleSender) extends Connector implements JsonSerializable
        {
            public ?int $tries = 1;

            public bool $allowBaseUrlOverride = false;

            private readonly SensitiveParameterValue $baseUrl;

            private readonly SensitiveParameterValue $authenticate;

            public function __construct(
                #[SensitiveParameter] BaseUrl $baseUrl,
                private readonly int $connectTimeoutSeconds,
                private readonly int $requestTimeoutSeconds,
                #[SensitiveParameter] Closure $authenticate,
                Sender $sender,
            ) {
                $this->baseUrl = new SensitiveParameterValue($baseUrl);
                $this->authenticate = new SensitiveParameterValue($authenticate);
                $this->sender = $sender;
            }

            public function resolveBaseUrl(): string
            {
                $baseUrl = $this->baseUrl->getValue();

                if (! $baseUrl instanceof BaseUrl) {
                    throw new LogicException('The base URL value is corrupted.');
                }

                return (string) $baseUrl;
            }

            protected function defaultAuth(): Authenticator
            {
                $authenticate = $this->authenticate->getValue();

                if (! $authenticate instanceof Closure) {
                    throw new LogicException('The credential authenticator is corrupted.');
                }

                return new class($authenticate) implements Authenticator, JsonSerializable
                {
                    private readonly SensitiveParameterValue $authenticate;

                    public function __construct(#[SensitiveParameter] Closure $authenticate)
                    {
                        $this->authenticate = new SensitiveParameterValue($authenticate);
                    }

                    public function set(PendingRequest $pendingRequest): void
                    {
                        $authenticate = $this->authenticate->getValue();

                        if (! $authenticate instanceof Closure) {
                            throw new LogicException('The credential authenticator is corrupted.');
                        }

                        $authenticate($pendingRequest);
                    }

                    /** @return array{token: string} */
                    public function __debugInfo(): array
                    {
                        return ['token' => '[REDACTED]'];
                    }

                    /** @return array{token: string} */
                    public function jsonSerialize(): array
                    {
                        return $this->__debugInfo();
                    }

                    /** @return never */
                    public function __clone()
                    {
                        throw new LogicException('Credential authenticators cannot be cloned.');
                    }

                    /** @return never */
                    public function __serialize(): array
                    {
                        throw new LogicException('Credential authenticators cannot be serialized.');
                    }

                    /** @param array<never, never> $data */
                    public function __unserialize(array $data): never
                    {
                        throw new LogicException('Credential authenticators cannot be unserialized.');
                    }
                };
            }

            /** @return array<string, bool|int> */
            protected function defaultConfig(): array
            {
                return [
                    'allow_redirects' => false,
                    'connect_timeout' => $this->connectTimeoutSeconds,
                    'http_errors' => false,
                    'stream' => true,
                    'timeout' => $this->requestTimeoutSeconds,
                    'verify' => true,
                ];
            }

            /** @return array{base_url: string, api_token: string} */
            public function __debugInfo(): array
            {
                return [
                    'base_url' => '[REDACTED]',
                    'api_token' => '[REDACTED]',
                ];
            }

            /** @return array{base_url: string, api_token: string} */
            public function jsonSerialize(): array
            {
                return $this->__debugInfo();
            }

            /** @return never */
            public function __clone()
            {
                throw new LogicException('Credentialed connectors cannot be cloned.');
            }

            /** @return never */
            public function __serialize(): array
            {
                throw new LogicException('Credentialed connectors cannot be serialized.');
            }

            /** @param array<never, never> $data */
            public function __unserialize(array $data): never
            {
                throw new LogicException('Credentialed connectors cannot be unserialized.');
            }
        };

        $authenticator = $connector->getAuthenticator();

        if (! $authenticator instanceof Authenticator) {
            throw new LogicException('The credential authenticator could not be sealed.');
        }

        $connector->authenticate($authenticator);

        return new FakturowniaClient($connector, $baseUrl);
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => self::Redacted];
    }

    public function jsonSerialize(): string
    {
        return self::Redacted;
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Secret values cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Secret values cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Secret values cannot be unserialized.');
    }
}
