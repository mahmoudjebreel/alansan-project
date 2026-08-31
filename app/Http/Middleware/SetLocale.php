<?php

namespace App\Http\Middleware;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App as AppFacade;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = session('locale', app(GeneralSettings::class)->default_locale);
        if (! in_array($locale, ['en', 'ar'], true)) {
            $locale = app(GeneralSettings::class)->default_locale;
        }

        AppFacade::setLocale($locale);

        return $next($request);
    }
}
