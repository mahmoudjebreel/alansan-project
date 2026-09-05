<?php

namespace App\Http\Controllers;

use App\Settings\GeneralSettings;
use Illuminate\Http\RedirectResponse;

/**
 * The EN / العربية switch in the user menu.
 *
 * The choice is kept in the session rather than on the user, so it is a
 * property of this browser and this sitting: a screener can read one report in
 * English without changing the language for everyone who shares the account.
 * SetLocale reads it back on the next request.
 *
 * A controller rather than a closure so the route table can be cached; see
 * routes/web.php.
 *
 * @see \App\Http\Middleware\SetLocale
 */
class SwitchLocaleController extends Controller
{
    /** The languages the panel is translated into. */
    public const SUPPORTED = ['ar', 'en'];

    public function __invoke(string $locale): RedirectResponse
    {
        // Anything else falls back to the configured default rather than being
        // stored: the value comes off the URL, and an unsupported locale in the
        // session would follow the user around every page afterwards.
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = app(GeneralSettings::class)->default_locale;
        }

        session(['locale' => $locale]);

        return back();
    }
}
