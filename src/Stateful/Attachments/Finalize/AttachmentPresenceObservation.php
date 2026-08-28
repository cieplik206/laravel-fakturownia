<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Attachments\Finalize;

use Cieplik206\Fakturownia\Stateful\Support\RejectsNativeSerialization;
use InvalidArgumentException;

final readonly class AttachmentPresenceObservation
{
    use RejectsNativeSerialization;

    /** @var list<string> */
    public array $fileNames;

    /** @param list<string> $fileNames */
    public function __construct(public int $attachmentsCount, array $fileNames)
    {
        $unique = array_values(array_unique($fileNames, SORT_STRING));

        if ($attachmentsCount < 0
            || $attachmentsCount > 10_000
            || count($unique) !== count($fileNames)
            || count($unique) > $attachmentsCount) {
            throw new InvalidArgumentException('Attachment presence observation is invalid.');
        }

        foreach ($unique as $fileName) {
            if ($fileName === '' || strlen($fileName) > 255 || preg_match('//u', $fileName) !== 1) {
                throw new InvalidArgumentException('Attachment presence file name is invalid.');
            }
        }

        sort($unique, SORT_STRING);
        $this->fileNames = $unique;
    }

    public function contains(string $fileName): bool
    {
        return in_array($fileName, $this->fileNames, true);
    }
}
