<?php

use App\Http\Controllers\ActivityHeartbeatController;
use App\Http\Controllers\ActivityPageLeaveController;
use App\Http\Controllers\CsvExportDownloadController;
use App\Http\Controllers\PdfExportDownloadController;
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

// User activity monitoring: a visible panel tab reporting in, and a tab
// being closed. Both answer 401 themselves when the session is gone rather
// than sitting behind `auth`, for the reason the keep-alive gives.
Route::post('/activity/heartbeat', ActivityHeartbeatController::class)
    ->name('activity.heartbeat');

Route::post('/activity/leave', ActivityPageLeaveController::class)
    ->name('activity.leave');

// A module's PDF report, collected as an ordinary download rather than
// returned through Livewire. Deliberately not behind `auth` for the reason
// the keep-alive gives: it answers an unknown or foreign ticket with a 404
// and a user who may no longer export with a 403, itself.
Route::get('/exports/pdf/{ticket}', PdfExportDownloadController::class)
    ->whereAlphaNumeric('ticket')
    ->name('exports.pdf');

// A listing too large for the XLSX download, collected as a CSV file that is
// written and checked in full before it is sent. Outside `auth` for the same
// reason as the PDF route: it answers 404 and 403 itself.
Route::get('/exports/csv/{ticket}', CsvExportDownloadController::class)
    ->whereAlphaNumeric('ticket')
    ->name('exports.csv');
