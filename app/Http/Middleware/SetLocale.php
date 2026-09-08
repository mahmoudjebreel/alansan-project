<?php

namespace App\Http\Middleware;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App as AppFacade;
use Throwable;

class SetLocale
{
    /** The locales the panel is translated into. */
    private const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next)
    {
        $locale = session('locale');

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = $this->configuredLocale();
        }

        AppFacade::setLocale($locale);

        return $next($request);
    }

    /**
     * The locale an operator chose, or the one the application ships with.
     *
     * The setting lives in the database and this runs on every panel request,
     * the sign-in screen included. An environment whose settings rows are not
     * there yet - one where the settings migrations have not been run - has to
     * stay able to serve a page in the language config carries, rather than
     * answering 500 everywhere and hiding the sign-in screen behind it.
     */
    private function configuredLocale(): string
    {
        try {
            $locale = app(GeneralSettings::class)->default_locale;
        } catch (Throwable) {
            $locale = null;
        }

        return in_array($locale, self::SUPPORTED, true)
            ? $locale
            : config('app.locale');
    }
}
