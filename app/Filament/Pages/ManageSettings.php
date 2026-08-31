<?php

namespace App\Filament\Pages;

use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ManageSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'الإعدادات';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $title = 'الإعدادات العامة';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.manage-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = app(GeneralSettings::class);

        $this->form->fill([
            'site_name' => $settings->site_name,
            'primary_color' => $settings->primary_color,
            'secondary_color' => $settings->secondary_color,
            'logo_path' => $settings->logo_path,
            'favicon_path' => $settings->favicon_path,
            'footer_text' => $settings->footer_text,
            'contact_info' => $settings->contact_info,
            'default_locale' => $settings->default_locale,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                \Filament\Schemas\Components\Section::make('هوية النظام')
                    ->description('تخصيص اسم وشعار النظام')
                    ->icon('heroicon-o-building-office')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            \Filament\Forms\Components\TextInput::make('site_name')
                                ->label('اسم النظام')
                                ->required()
                                ->maxLength(255),
                            \Filament\Forms\Components\Select::make('default_locale')
                                ->label('اللغة الافتراضية')
                                ->options([
                                    'ar' => 'العربية',
                                    'en' => 'English',
                                ])
                                ->default('ar')
                                ->required(),
                        ]),
                    ]),

                \Filament\Schemas\Components\Section::make('الألوان')
                    ->description('تخصيص ألوان لوحة التحكم')
                    ->icon('heroicon-o-swatch')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            \Filament\Forms\Components\ColorPicker::make('primary_color')
                                ->label('اللون الأساسي')
                                ->required(),
                            \Filament\Forms\Components\ColorPicker::make('secondary_color')
                                ->label('اللون الثانوي')
                                ->required(),
                        ]),
                    ]),

                \Filament\Schemas\Components\Section::make('معلومات إضافية')
                    ->description('تذييل الصفحة ومعلومات التواصل')
                    ->icon('heroicon-o-information-circle')
                    ->collapsible()
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('footer_text')
                            ->label('نص التذييل')
                            ->maxLength(500),
                        \Filament\Forms\Components\Textarea::make('contact_info')
                            ->label('معلومات التواصل')
                            ->rows(3)
                            ->maxLength(1000),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('حفظ الإعدادات')
                ->icon('heroicon-o-check')
                ->action(function () {
                    $data = $this->form->getState();
                    $settings = app(GeneralSettings::class);

                    $settings->site_name = $data['site_name'];
                    $settings->primary_color = $data['primary_color'];
                    $settings->secondary_color = $data['secondary_color'];
                    $settings->logo_path = $data['logo_path'] ?? '';
                    $settings->favicon_path = $data['favicon_path'] ?? '';
                    $settings->footer_text = $data['footer_text'] ?? '';
                    $settings->contact_info = $data['contact_info'] ?? '';
                    $settings->default_locale = $data['default_locale'] ?? 'ar';

                    $settings->save();

                    Notification::make()
                        ->title('تم حفظ الإعدادات بنجاح')
                        ->success()
                        ->send();

                    $this->redirect(static::getUrl(), navigate: false);
                }),
        ];
    }
}
