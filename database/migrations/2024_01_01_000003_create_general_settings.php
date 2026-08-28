<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class CreateGeneralSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.site_name', 'أرض الإنسان - نظام المسح التغذوي');
        $this->migrator->add('general.primary_color', '#10b981');
        $this->migrator->add('general.secondary_color', '#3b82f6');
        $this->migrator->add('general.logo_path', '');
        $this->migrator->add('general.favicon_path', '');
        $this->migrator->add('general.footer_text', '© 2024 أرض الإنسان - جميع الحقوق محفوظة');
        $this->migrator->add('general.contact_info', '');
        $this->migrator->add('general.default_locale', 'ar');
    }
}
