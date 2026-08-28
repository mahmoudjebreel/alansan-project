<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $site_name;
    public string $primary_color;
    public string $secondary_color;
    public string $logo_path;
    public string $favicon_path;
    public string $footer_text;
    public string $contact_info;
    public string $default_locale;

    public static function group(): string
    {
        return 'general';
    }
}
