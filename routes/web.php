<?php

use App\Support\ChildDuplicateChecker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Temporary deployment helper
|--------------------------------------------------------------------------
| Clears the framework, Filament and OPcache caches from the browser, for
| servers where no shell access is available after uploading files by hand.
|
| Guarded by the secret below and by nothing else: change the token before
| uploading, and DELETE this route once the deployment is confirmed.
*/
Route::get('/deploy-refresh/{token}', function (string $token) {
    $expected = 'eb3a269ccb2fdc62bde9d492966e5bae730e5d70';

    abort_unless(hash_equals($expected, $token), 404);

    $steps = [];

    foreach (['optimize:clear', 'filament:optimize-clear'] as $command) {
        try {
            Artisan::call($command);
            $steps[$command] = 'OK';
        } catch (\Throwable $e) {
            $steps[$command] = 'FAILED: ' . $e->getMessage();
        }
    }

    clearstatcache(true);

    if (function_exists('opcache_reset')) {
        $steps['opcache_reset'] = opcache_reset() ? 'OK' : 'FAILED';
    } else {
        $steps['opcache_reset'] = 'SKIPPED (OPcache not enabled)';
    }

    // Confirm the uploaded files are the ones actually being served.
    $resource = base_path('app/Filament/Resources/ChildResource.php');
    $resourceSource = is_readable($resource) ? (string) file_get_contents($resource) : '';

    $checks = [
        'ChildDuplicateChecker class loads' => class_exists(ChildDuplicateChecker::class),
        'visit_type field is locked' => str_contains($resourceSource, "Select::make('visit_type')")
            && str_contains($resourceSource, '->disabled()'),
        'duplicate check excludes trashed' => ! str_contains($resourceSource, 'Child::withTrashed()'),
        'relapse rule wired to MUAC' => str_contains($resourceSource, 'syncVisitType'),
    ];

    return response()->json([
        'steps' => $steps,
        'checks' => $checks,
        'deployed' => ! in_array(false, $checks, true),
    ], options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
});

Route::get('/', function () {
    return auth()->check() ? redirect('/admin') : redirect('/admin/login');
});

Route::get('/locale/{locale}', function (string $locale) {
    $locale = in_array($locale, ['en', 'ar'], true)
        ? $locale
        : config('app.locale');

    session(['locale' => $locale]);

    return back();
})->name('locale.switch');
