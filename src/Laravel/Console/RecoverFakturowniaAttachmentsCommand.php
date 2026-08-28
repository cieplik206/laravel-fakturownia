<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Laravel\Console;

use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\AdvanceAttachmentWorkflow;
use Cieplik206\Fakturownia\Stateful\Attachments\Workflow\Contracts\AttachmentWorkflowStore;
use Illuminate\Console\Command;

final class RecoverFakturowniaAttachmentsCommand extends Command
{
    protected $signature = 'fakturownia:attachments:recover {--limit=50}';

    protected $description = 'Recover uploaded Fakturownia attachments missing their finalize child operation';

    public function handle(AttachmentWorkflowStore $store, AdvanceAttachmentWorkflow $workflows): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            $this->components->error('The recovery limit must be between 1 and 100.');

            return self::INVALID;
        }

        $recovered = 0;

        foreach ($store->pendingFinalize($limit) as $pending) {
            $workflows->advance($pending);
            $recovered++;
        }

        $this->components->info("Recovered {$recovered} attachment workflow(s).");

        return self::SUCCESS;
    }
}
