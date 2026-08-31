<?php
// Step 2: fresh process — check if a Viewer user still "has" children.export
$user = \App\Models\User::factory()->create(['email' => 'repro-test@example.com']);
$user->assignRole('Viewer');

$result = $user->hasPermissionTo('children.export');
echo 'Viewer hasPermissionTo(children.export) after revocation: ' . var_export($result, true) . "\n";
echo $result ? "BUG REPRODUCED: stale cache grants revoked permission\n" : "no bug (cache was fresh)\n";

$user->forceDelete();
