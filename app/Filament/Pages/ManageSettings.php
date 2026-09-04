<?php

namespace App\Filament\Pages;

use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use App\Support\PublicUploads;
use Illuminate\Support\Arr;
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

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.manage-settings';

    public static function getNavigationLabel(): string
    {
        return __('ui.settings.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.system');
    }

    public function getTitle(): string
    {
        return __('ui.settings.title');
    }

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    /**
     * The settings this page owns, in the order the form presents them.
     *
     * Kept as one list so mount() and save() can never disagree about which
     * properties the page is responsible for - a mismatch used to mean a field
     * that displayed correctly but was silently dropped on save.
     *
     * @var array<string>
     */
    private const EDITABLE = [
        'site_name', 'logo_path', 'favicon_path', 'login_tagline',
        'primary_color', 'secondary_color', 'default_theme',
        'default_locale', 'timezone', 'default_pagination',
        'footer_text', 'contact_info', 'support_email', 'support_phone',
    ];

    public function mount(): void
    {
        $settings = app(GeneralSettings::class);

        $this->form->fill(
            collect(self::EDITABLE)
                ->mapWithKeys(fn (string $property): array => [$property => $settings->{$property}])
                ->all(),
        );
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                \Filament\Schemas\Components\Section::make(__('ui.settings.identity_section'))
                    ->description(__('ui.settings.identity_description'))
                    ->icon('heroicon-o-building-office')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            \Filament\Forms\Components\TextInput::make('site_name')
                                ->label(__('ui.settings.site_name'))
                                ->required()
                                ->maxLength(255),
                            \Filament\Forms\Components\TextInput::make('login_tagline')
                                ->label(__('ui.settings.login_tagline'))
                                ->helperText(__('ui.settings.login_tagline_help'))
                                ->maxLength(255),
                            // Uploads rather than typed paths: the operator has
                            // the file, not a path inside public/, and a typo
                            // there used to show as a broken image on the
                            // sign-in page with nothing to say why.
                            \Filament\Forms\Components\FileUpload::make('logo_path')
                                ->label(__('ui.settings.logo'))
                                ->helperText(__('ui.settings.logo_help'))
                                ->image()
                                ->disk(PublicUploads::DISK)
                                ->directory('branding')
                                ->visibility('public')
                                ->imagePreviewHeight('80')
                                ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/jpeg', 'image/webp'])
                                ->maxSize(2048),
                            \Filament\Forms\Components\FileUpload::make('favicon_path')
                                ->label(__('ui.settings.favicon'))
                                ->helperText(__('ui.settings.favicon_help'))
                                ->image()
                                ->disk(PublicUploads::DISK)
                                ->directory('branding')
                                ->visibility('public')
                                ->imagePreviewHeight('48')
                                ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon'])
                                ->maxSize(1024),
                        ]),
                    ]),

                \Filament\Schemas\Components\Section::make(__('ui.settings.appearance_section'))
                    ->description(__('ui.settings.appearance_description'))
                    ->icon('heroicon-o-swatch')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(3)->schema([
                            \Filament\Forms\Components\ColorPicker::make('primary_color')
                                ->label(__('ui.settings.primary_color'))
                                ->required(),
                            \Filament\Forms\Components\ColorPicker::make('secondary_color')
                                ->label(__('ui.settings.secondary_color'))
                                ->required(),
                            \Filament\Forms\Components\Select::make('default_theme')
                                ->label(__('ui.settings.default_theme'))
                                ->options([
                                    'system' => __('ui.settings.theme_system'),
                                    'light' => __('ui.settings.theme_light'),
                                    'dark' => __('ui.settings.theme_dark'),
                                ])
                                ->native(false)
                                ->required(),
                        ]),
                    ]),

                \Filament\Schemas\Components\Section::make(__('ui.settings.runtime_section'))
                    ->description(__('ui.settings.runtime_description'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(3)->schema([
                            \Filament\Forms\Components\Select::make('default_locale')
                                ->label(__('ui.settings.default_locale'))
                                ->options([
                                    'ar' => 'العربية',
                                    'en' => 'English',
                                ])
                                ->native(false)
                                ->required(),
                            \Filament\Forms\Components\Select::make('timezone')
                                ->label(__('ui.settings.timezone'))
                                ->options(static::timezoneOptions())
                                ->searchable()
                                ->native(false)
                                ->required(),
                            \Filament\Forms\Components\Select::make('default_pagination')
                                ->label(__('ui.settings.page_size'))
                                ->helperText(__('ui.settings.page_size_help'))
                                ->options([10 => 10, 25 => 25, 50 => 50, 100 => 100])
                                ->native(false)
                                ->required(),
                        ]),
                    ]),

                \Filament\Schemas\Components\Section::make(__('ui.settings.support_section'))
                    ->description(__('ui.settings.support_description'))
                    ->icon('heroicon-o-information-circle')
                    ->collapsible()
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            \Filament\Forms\Components\TextInput::make('support_email')
                                ->label(__('ui.settings.support_email'))
                                ->email()
                                ->maxLength(255),
                            \Filament\Forms\Components\TextInput::make('support_phone')
                                ->label(__('ui.settings.support_phone'))
                                ->tel()
                                ->maxLength(50),
                        ]),
                        \Filament\Forms\Components\TextInput::make('footer_text')
                            ->label(__('ui.settings.footer_text'))
                            ->maxLength(500),
                        \Filament\Forms\Components\Textarea::make('contact_info')
                            ->label(__('ui.settings.contact_info'))
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
                ->label(__('ui.settings.save'))
                ->icon('heroicon-o-check')
                ->action(function () {
                    $data = $this->form->getState();
                    $settings = app(GeneralSettings::class);

                    foreach (self::EDITABLE as $property) {
                        $value = $data[$property] ?? null;

                        // A FileUpload hands back an array keyed by upload id
                        // even when only one file is allowed; the setting is a
                        // single path, so the first entry is the whole answer.
                        if (is_array($value)) {
                            $value = Arr::first($value);
                        }

                        // An int property must not be handed the string a
                        // <select> submits, or the settings cast rejects it.
                        $settings->{$property} = is_int($settings->{$property})
                            ? (int) ($value ?? $settings->{$property})
                            : (string) ($value ?? '');
                    }

                    $settings->save();

                    Notification::make()
                        ->title(__('ui.settings.saved'))
                        ->success()
                        ->send();

                    $this->redirect(static::getUrl(), navigate: false);
                }),
        ];
    }

    /**
     * The timezones offered on the settings page.
     *
     * The full IANA list is close to five hundred entries and unusable in a
     * select; these are the ones this programme actually operates in, plus UTC
     * as a neutral fallback.
     *
     * @return array<string, string>
     */
    public static function timezoneOptions(): array
    {
        return [
            'Asia/Gaza' => __('ui.timezones.Asia/Gaza'),
            'Asia/Hebron' => __('ui.timezones.Asia/Hebron'),
            'Asia/Amman' => __('ui.timezones.Asia/Amman'),
            'Asia/Beirut' => __('ui.timezones.Asia/Beirut'),
            'Africa/Cairo' => __('ui.timezones.Africa/Cairo'),
            'Asia/Riyadh' => __('ui.timezones.Asia/Riyadh'),
            'Europe/Istanbul' => __('ui.timezones.Europe/Istanbul'),
            'UTC' => __('ui.timezones.UTC'),
        ];
    }
}
