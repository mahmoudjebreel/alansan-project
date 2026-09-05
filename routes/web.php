<?php

use App\Http\Controllers\SessionKeepAliveController;
use App\Http\Controllers\SwitchLocaleController;
use Illuminate\Support\Facades\Route;

/*
 * Every route here points at a controller rather than a closure.
 *
 * That is not a style preference: `php artisan route:cache` refuses to
 * serialise a closure, so a single one anywhere in this file makes the whole
 * route table uncacheable and every request re-registers all of it. The panel
 * is deployed by unpacking a zip on a host with no terminal, so the caches are
 * built from the Cache Management page - and there is no point offering a
 * button that cannot work.
 *
 * @see \App\Filament\Pages\CacheManagement
 */

Route::redirect('/', '/admin')->name('home');

Route::get('/session/keep-alive', SessionKeepAliveController::class)
    ->name('session.keep-alive');

Route::get('/locale/{locale}', SwitchLocaleController::class)
    ->name('locale.switch');
