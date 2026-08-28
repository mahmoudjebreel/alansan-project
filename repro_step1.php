<?php
// Step 1: prime spatie cache, then raw-sync role permissions (simulates Filament ->relationship() save)
app(\Spatie\Permission\PermissionRegistrar::class)->getPermissions();

$role = \Spatie\Permission\Models\Role::findByName('Viewer');
$removeId = \Spatie\Permission\Models\Permission::findByName('children.export')->id;
$keep = array_diff($role->permissions()->pluck('permissions.id')->all(), [$removeId]);
$role->permissions()->sync($keep);

echo "step1 done: children.export removed from Viewer via raw sync (no cache flush)\n";
