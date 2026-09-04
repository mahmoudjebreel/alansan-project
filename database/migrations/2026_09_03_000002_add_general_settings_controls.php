<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The settings the panel gained alongside the redesigned sign-in screen and
 * the wider listings.
 *
 * Each one is read somewhere: the theme and page size by the panel, the
 * timezone by the application's date handling, the tagline by the sign-in
 * screen, and the support details by the footer. Defaults reproduce exactly
 * what the system did before this migration, so running it changes nothing
 * until an operator edits a value.
 *
 * @see \App\Settings\GeneralSettings
 */
return new class extends SettingsMigration
{
    /**
     * @var array<string, mixed>
     */
    private const ADDED = [
        'general.login_tagline' => 'نظام المسح والمتابعة التغذوية',
        'general.default_theme' => 'system',
        'general.timezone' => 'Asia/Gaza',
        'general.default_pagination' => 25,
        'general.support_email' => '',
        'general.support_phone' => '',
    ];

    public function up(): void
    {
        foreach (self::ADDED as $key => $default) {
            // A re-run on a database that already carries the key must not
            // fail, and must not overwrite what the operator has set.
            if (! $this->migrator->exists($key)) {
                $this->migrator->add($key, $default);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::ADDED) as $key) {
            if ($this->migrator->exists($key)) {
                $this->migrator->delete($key);
            }
        }
    }
};
