<?php

namespace App\Settings;

use App\Support\PublicUploads;
use Spatie\LaravelSettings\Settings;

/**
 * System-wide settings the operator can change from the panel.
 *
 * Every property here is wired to something visible: a setting that nothing
 * reads is worse than no setting at all, because it looks as though changing
 * it did something.
 *
 * @see \App\Filament\Pages\ManageSettings   the form that edits these
 * @see \Database\Seeders\SettingsSeeder     the defaults for a fresh install
 */
class GeneralSettings extends Settings
{
    // --- Identity -------------------------------------------------------

    public string $site_name;

    public string $logo_path;

    public string $favicon_path;

    /** Shown under the site name on the sign-in screen. */
    public string $login_tagline;

    // --- Appearance -----------------------------------------------------

    public string $primary_color;

    public string $secondary_color;

    /** 'light', 'dark' or 'system' - the panel's opening theme. */
    public string $default_theme;

    // --- Behaviour ------------------------------------------------------

    public string $default_locale;

    /** IANA timezone every date in the panel is displayed in. */
    public string $timezone;

    /** Rows per page the listings open on. */
    public int $default_pagination;

    // --- Contact --------------------------------------------------------

    public string $footer_text;

    public string $contact_info;

    public string $support_email;

    public string $support_phone;

    public static function group(): string
    {
        return 'general';
    }

    /**
     * The logo's URL, or null when there is none to show.
     *
     * Three shapes have to keep working: a file uploaded on the settings page,
     * a path typed in by hand before uploads existed, and the organisation's
     * own logo shipped in public/images. PublicUploads sorts out which is
     * which and always answers with a root-relative URL or null - never a link
     * to a file that is not there.
     */
    public function logoUrl(): ?string
    {
        return PublicUploads::url($this->logo_path);
    }

    public function faviconUrl(): ?string
    {
        return PublicUploads::url($this->favicon_path);
    }

    /**
     * The page sizes offered in every listing.
     *
     * The configured default is always one of them, so a value typed into the
     * settings form cannot leave the tables with a page size they refuse.
     *
     * @return array<int>
     */
    public function paginationOptions(): array
    {
        $options = array_unique([10, 25, 50, 100, $this->default_pagination]);

        sort($options);

        return array_values($options);
    }
}
