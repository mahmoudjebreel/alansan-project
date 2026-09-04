<?php

namespace App\Filament\Pages\Auth;

use App\Settings\GeneralSettings;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The panel's sign-in page.
 *
 * Only the wording is ours: the form, the rate limiting, the multi-factor
 * challenge and the session handling all stay with Filament's page, because
 * they are the parts that must not be re-implemented by hand. Everything
 * visual is done in CSS against the classes this page already renders, so an
 * upgrade to Filament cannot silently drop a hand-copied Blade template.
 *
 * @see resources/css/filament/admin/custom.css
 * @see resources/views/filament/auth/login-brand.blade.php
 */
class Login extends BaseLogin
{
    public function getHeading(): string | Htmlable | null
    {
        return __('auth.login_heading');
    }

    /**
     * The site name is not repeated here: the brand block above the heading
     * already carries it.
     */
    public function getSubheading(): string | Htmlable | null
    {
        return __('auth.login_subheading');
    }

    public function getTitle(): string | Htmlable
    {
        return __('auth.login_title');
    }

    /**
     * The configured site name, or the programme's own name when the setting
     * has never been filled in.
     */
    public static function siteName(): string
    {
        $siteName = app(GeneralSettings::class)->site_name;

        return filled($siteName) ? $siteName : __('auth.default_site_name');
    }
}
