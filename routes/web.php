<?php

use Illuminate\Support\Facades\Route;

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
