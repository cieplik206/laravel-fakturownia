<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\Stateful\Artifacts\Maintenance;

enum ArtifactMaintenanceIssue: string
{
    case SharedStorageUnverified = 'shared_storage_unverified';
    case MaintenanceCapabilitiesIncomplete = 'maintenance_capabilities_incomplete';
    case RetentionPolicyMismatch = 'retention_policy_mismatch';
    case OrphanBacklog = 'orphan_backlog';
    case MissingObject = 'missing_object';
    case MetadataMismatch = 'metadata_mismatch';
    case SizeMismatch = 'size_mismatch';
    case ChecksumMismatch = 'checksum_mismatch';
    case ObjectUnreadable = 'object_unreadable';
    case FreshObjectInOrphanScan = 'fresh_object_in_orphan_scan';
    case ObjectChangedBeforeDelete = 'object_changed_before_delete';
    case ObjectDeleteFailed = 'object_delete_failed';
    case LifecycleWriteFailed = 'lifecycle_write_failed';

    public function severity(): ArtifactMaintenanceSeverity
    {
        return $this === self::OrphanBacklog
            ? ArtifactMaintenanceSeverity::Warning
            : ArtifactMaintenanceSeverity::Critical;
    }
}
