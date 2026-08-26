<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Console;

use Cieplik206\Fakturownia\Laravel\Contracts\ConfigurationPublisher;
use Illuminate\Console\Command;

final class InstallFakturowniaCommand extends Command
{
    protected $signature = 'fakturownia:install {--force : Overwrite an existing configuration file}';

    protected $description = 'Publish the Fakturownia SDK configuration';

    public function __construct(private readonly ConfigurationPublisher $configurationPublisher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $exitCode = $this->configurationPublisher->publish((bool) $this->option('force'));

        if ($exitCode !== self::SUCCESS) {
            $this->components->error('Fakturownia SDK configuration could not be installed.');

            return self::FAILURE;
        }

        $this->components->info('Fakturownia SDK configuration is installed.');

        return self::SUCCESS;
    }
}
