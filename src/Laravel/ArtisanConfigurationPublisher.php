<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel;

use Cieplik206\Fakturownia\Laravel\Contracts\ConfigurationPublisher;
use Illuminate\Contracts\Console\Kernel;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class ArtisanConfigurationPublisher implements ConfigurationPublisher, JsonSerializable
{
    private SensitiveParameterValue $artisan;

    public function __construct(#[SensitiveParameter] Kernel $artisan)
    {
        $this->artisan = new SensitiveParameterValue($artisan);
    }

    public function publish(bool $force): int
    {
        return $this->artisan()->call('vendor:publish', [
            '--provider' => FakturowniaServiceProvider::class,
            '--tag' => 'fakturownia-config',
            '--force' => $force,
        ]);
    }

    /** @return array{artisan: string, credentials: string} */
    public function __debugInfo(): array
    {
        return [
            'artisan' => '[REDACTED]',
            'credentials' => '[REDACTED]',
        ];
    }

    /** @return array{artisan: string, credentials: string} */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @return never */
    public function __clone()
    {
        throw new LogicException('Configuration publishers cannot be cloned.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Configuration publishers cannot be serialized.');
    }

    /** @param array<never, never> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Configuration publishers cannot be unserialized.');
    }

    private function artisan(): Kernel
    {
        $artisan = $this->artisan->getValue();

        if (! $artisan instanceof Kernel) {
            throw new LogicException('The Artisan kernel is corrupted.');
        }

        return $artisan;
    }
}
