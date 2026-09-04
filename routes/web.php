<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect('/admin') : redirect('/admin/login');
});

/**
 * Keeps an open form's session alive.
 *
 * The data-entry forms in this system are long - the Children form alone has
 * sixty-eight fields across four tabs - and are routinely left open while the
 * screener goes and finds a missing answer. Laravel's session expires after
 * SESSION_LIFETIME minutes of no requests, and the next Livewire round trip
 * then comes back 419: "This page has expired", losing everything typed.
 *
 * The panel pings this while a tab is open and visible. Touching the session
 * is the whole job - the web middleware group reading it is what pushes the
 * expiry forward - so the response is deliberately empty.
 *
 * Deliberately not behind `auth`: it reads nothing and returns nothing, and the
 * auth middleware would answer an already-expired session with a redirect to a
 * route name this application does not define, turning the very case this
 * exists for into a 500.
 *
 * @see resources/views/filament/scripts/dashboard-alerts.blade.php
 */
Route::get('/session/keep-alive', function () {
    return response()->noContent();
})->name('session.keep-alive');

Route::get('/locale/{locale}', function (string $locale) {
    $locale = in_array($locale, ['en', 'ar'], true)
        ? $locale
        : config('app.locale');

    session(['locale' => $locale]);

    return back();
})->name('locale.switch');
