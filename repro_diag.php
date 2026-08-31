<?php
echo "spatie version: " . \Composer\InstalledVersions::getVersion('spatie/laravel-permission') . "\n";
print_r(\Spatie\Permission\Models\Role::all(['id', 'name', 'guard_name'])->toArray());
echo "default guard: " . config('auth.defaults.guard') . "\n";
$u = new \App\Models\User();
echo "user guard_name: " . var_export($u->guard_name ?? null, true) . "\n";
echo "findByName: " . \Spatie\Permission\Models\Role::findByName('Viewer')->id . "\n";
