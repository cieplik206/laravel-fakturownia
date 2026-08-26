<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

enum ArtifactPurgeOutcome: string
{
    case Deleted = 'deleted';
    case AlreadyAbsent = 'already_absent';
    case RejectedChanged = 'rejected_changed';
    case DeadlineExceeded = 'deadline_exceeded';
}
