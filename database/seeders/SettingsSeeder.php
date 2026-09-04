<?php

namespace Database\Seeders;

use App\Settings\GeneralSettings;
use App\Settings\NotificationSettings;
use App\Support\Notifications\ActionType;
use Illuminate\Database\Seeder;
use Spatie\LaravelSettings\Models\SettingsProperty;

/**
 * Bring the settings tables up to a complete, usable state.
 *
 * The settings migrations create a property the first time they run and are
 * then never repeated, so a database restored from an older dump - or one
 * where a property was deleted by hand - can be left missing keys that
 * GeneralSettings declares. Reading such a settings object throws, which takes
 * the whole panel down rather than one page.
 *
 * This seeder closes that gap: it writes any missing property and leaves every
 * existing value exactly as the operator set it. It is safe to run on a live
 * database, and safe to run repeatedly.
 *
 * The one value it will fill in on an existing install is the logo path, and
 * only while it is still blank - see BRANDED, below.
 */
class SettingsSeeder extends Seeder
{
    /**
     * Where the organisation's own logo lives.
     *
     * Inside public/ rather than in the uploads directory on purpose: it is
     * part of the application, so it travels with every deployment, survives a
     * reseed, and does not have to be uploaded again by hand on a new install.
     * Drop the file at public/images/ard-el-insan-logo.png and it is picked up
     * - the sign-in page, the sidebar brand and the settings page all read
     * this one setting. Until the file is there the panel draws its built-in
     * mark, never a broken image.
     */
    public const LOGO_PATH = 'images/ard-el-insan-logo.png';

    /**
     * Values this seeder is allowed to fill in on an install that already has
     * the property, while - and only while - the stored value is still blank.
     *
     * The rule everywhere else is that the operator's value wins, and it still
     * does: a logo they have chosen, or deliberately cleared and replaced, is
     * never overwritten. This exists so the shipped logo appears without
     * anybody having to go and set it on every install.
     *
     * @var array<string, array<string, string>>
     */
    private const BRANDED = [
        'general' => [
            'logo_path' => self::LOGO_PATH,
        ],
    ];

    /**
     * The defaults for a fresh install, per settings group.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEFAULTS = [
        'general' => [
            // Identity
            'site_name' => 'أرض الإنسان - نظام المسح التغذوي',
            'logo_path' => self::LOGO_PATH,
            'favicon_path' => '',
            'login_tagline' => 'نظام المسح والمتابعة التغذوية',

            // Appearance
            'primary_color' => '#10b981',
            'secondary_color' => '#3b82f6',
            'default_theme' => 'system',

            // Behaviour
            'default_locale' => 'ar',
            'timezone' => 'Asia/Gaza',
            'default_pagination' => 25,

            // Contact
            'footer_text' => '© 2024 أرض الإنسان - جميع الحقوق محفوظة',
            'contact_info' => '',
            'support_email' => '',
            'support_phone' => '',
        ],

        'notifications' => [
            'enabled' => true,
            'notify_self_actions' => false,
            'enabled_actions' => null,       // filled in by run(): every action type
            'recipient_user_ids' => [],      // empty means every Super Admin
            'group_window_seconds' => 60,
        ],
    ];

    public function run(): void
    {
        $defaults = self::DEFAULTS;

        // The enabled action types are the ActionType list itself, so a newly
        // added action is notifiable on a fresh install without editing two
        // places that then drift apart.
        $defaults['notifications']['enabled_actions'] = ActionType::all();

        $written = 0;

        foreach ($defaults as $group => $properties) {
            $written += $this->seedGroup($group, $properties);
        }

        foreach (self::BRANDED as $group => $properties) {
            $written += $this->fillBlanks($group, $properties);
        }

        // The settings cache holds the shape the objects had a moment ago.
        app(GeneralSettings::class)->refresh();
        app(NotificationSettings::class)->refresh();

        $this->command?->info($written === 0
            ? 'Settings: nothing missing.'
            : "Settings: wrote {$written} missing propert" . ($written === 1 ? 'y' : 'ies') . '.');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function seedGroup(string $group, array $properties): int
    {
        $existing = SettingsProperty::query()
            ->where('group', $group)
            ->pluck('name')
            ->all();

        $written = 0;

        foreach ($properties as $name => $value) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            SettingsProperty::query()->create([
                'group' => $group,
                'name' => $name,
                'locked' => false,
                'payload' => json_encode($value, JSON_UNESCAPED_UNICODE),
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * Write a value onto a property that exists but is still blank.
     *
     * @param  array<string, string>  $properties
     */
    private function fillBlanks(string $group, array $properties): int
    {
        $written = 0;

        foreach ($properties as $name => $value) {
            $property = SettingsProperty::query()
                ->where('group', $group)
                ->where('name', $name)
                ->first();

            // Missing (seedGroup just wrote it), locked, or already carrying
            // something the operator chose - all three are left alone.
            if (! $property || $property->locked || filled(json_decode($property->payload, true))) {
                continue;
            }

            $property->update(['payload' => json_encode($value, JSON_UNESCAPED_UNICODE)]);

            $written++;
        }

        return $written;
    }
}
