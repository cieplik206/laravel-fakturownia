<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Attachments\Finalize\Contracts\AttachmentPresenceReader;
use Cieplik206\Fakturownia\Stateful\FakturowniaManager;
use Cieplik206\IntegrationOperations\ValueObjects\ConnectionKey;

final readonly class FakturowniaAttachmentPresenceReader implements AttachmentPresenceReader
{
    public function __construct(private FakturowniaManager $manager) {}

    public function observe(ConnectionKey $connection, string $remoteId): ?AttachmentPresenceObservation
    {
        $extra = $this->manager->connection($connection)->read()->invoices()->get($remoteId)->extra();
        $attachments = $extra['attachments'] ?? null;

        if (! is_array($attachments) || ! array_is_list($attachments)) {
            return null;
        }

        $fileNames = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                return null;
            }

            $fileName = $attachment['file_name'] ?? $attachment['filename'] ?? $attachment['name'] ?? null;

            if (! is_string($fileName)) {
                return null;
            }

            $fileNames[] = $fileName;
        }

        return new AttachmentPresenceObservation(count($attachments), $fileNames);
    }
}
