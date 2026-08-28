<?php

namespace App\Observers;

use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Flushes Spatie's cached permission map whenever role/permission
 * assignments change — including changes made via Filament
 * relationship selects (raw pivot sync), which otherwise bypass
 * Spatie's built-in cache flush and leave stale grants.
 */
class PermissionCacheObserver
{
    public function saved(Role|Permission $model): void
    {
        $this->flush();
    }

    public function deleted(Role|Permission $model): void
    {
        $this->flush();
    }

    public function pivotAttached(Role $role, string $relation, array $ids, array $attributes): void
    {
        $this->flush();
    }

    public function pivotDetached(Role $role, string $relation, array $ids): void
    {
        $this->flush();
    }

    public function pivotUpdated(Role $role, string $relation, array $ids, array $attributes): void
    {
        $this->flush();
    }

    protected function flush(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
