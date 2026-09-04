<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Settings\NotificationSettings;
use App\Support\Notifications\ActionType;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * Settings for the Super Admin data-action notifications.
 *
 * Kept as its own page rather than as a section of ManageSettings: that page
 * saves all of its fields in one action, and folding an unrelated group into
 * it would tie the two together. It sits in the same system-management
 * group, directly under the general settings.
 */
class NotificationSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.notification-settings-page';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('ui.notification_settings.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.system');
    }

    public function getTitle(): string
    {
        return __('ui.notification_settings.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('notifications.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = app(NotificationSettings::class);

        $this->form->fill([
            'enabled' => $settings->enabled,
            'notify_self_actions' => $settings->notify_self_actions ?? false,
            'enabled_actions' => $settings->enabled_actions,
            'recipient_user_ids' => $settings->recipient_user_ids,
            'group_window_seconds' => $settings->group_window_seconds,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                \Filament\Schemas\Components\Section::make(__('ui.notification_settings.switch_section'))
                    ->description(__('ui.notification_settings.switch_description'))
                    ->icon('heroicon-o-power')
                    ->schema([
                        Forms\Components\Toggle::make('enabled')
                            ->label(__('ui.notification_settings.enabled'))
                            ->helperText(__('ui.notification_settings.enabled_help'))
                            ->extraAttributes(['aria-label' => __('ui.notification_settings.enabled')]),
                        Forms\Components\Toggle::make('notify_self_actions')
                            ->label(__('ui.notification_settings.notify_self'))
                            ->helperText(__('ui.notification_settings.notify_self_help'))
                            ->extraAttributes(['aria-label' => __('ui.notification_settings.notify_self')]),
                    ]),

                \Filament\Schemas\Components\Section::make(__('ui.notification_settings.actions_section'))
                    ->description(__('ui.notification_settings.actions_description'))
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        Forms\Components\CheckboxList::make('enabled_actions')
                            ->label(__('ui.notification_settings.enabled_actions'))
                            ->hiddenLabel()
                            ->options(ActionType::options())
                            ->columns(3)
                            ->bulkToggleable(),
                    ]),

                \Filament\Schemas\Components\Section::make(__('ui.notification_settings.recipients_section'))
                    ->description(__('ui.notification_settings.recipients_description'))
                    ->icon('heroicon-o-user-group')
                    ->schema([
                        Forms\Components\Select::make('recipient_user_ids')
                            ->label(__('ui.notification_settings.recipients'))
                            ->helperText(__('ui.notification_settings.recipients_help'))
                            ->placeholder(__('ui.notification_settings.recipients_placeholder'))
                            ->multiple()
                            // Every user, not only the Super Admins: naming an
                            // Admin here is what makes the bell ring for them.
                            ->options(fn (): array => static::recipientOptions())
                            ->searchable()
                            ->native(false),
                    ]),

                \Filament\Schemas\Components\Section::make(__('ui.notification_settings.grouping_section'))
                    ->description(__('ui.notification_settings.grouping_description'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->schema([
                        Forms\Components\TextInput::make('group_window_seconds')
                            ->label(__('ui.notification_settings.group_window'))
                            ->helperText(__('ui.notification_settings.group_window_help'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(3600)
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('ui.common.save'))
                ->icon('heroicon-o-check')
                ->action(function (): void {
                    $data = $this->form->getState();
                    $settings = app(NotificationSettings::class);

                    $settings->enabled = (bool) ($data['enabled'] ?? false);
                    $settings->notify_self_actions = (bool) ($data['notify_self_actions'] ?? false);
                    $settings->enabled_actions = array_values(array_intersect(
                        $data['enabled_actions'] ?? [],
                        ActionType::all(),
                    ));
                    $settings->recipient_user_ids = array_values(array_map(
                        'intval',
                        $data['recipient_user_ids'] ?? [],
                    ));
                    $settings->group_window_seconds = max(0, (int) ($data['group_window_seconds'] ?? 0));

                    $settings->save();

                    Notification::make()
                        ->title(__('ui.notification_settings.saved'))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Every user the panel can notify, labelled with the role they hold so two
     * people with the same name can be told apart.
     *
     * @return array<int, string>
     */
    protected static function recipientOptions(): array
    {
        return User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [
                $user->getKey() => $user->name . ' — ' . ($user->getRoleNames()->first() ?? __('ui.notification_settings.no_role')),
            ])
            ->all();
    }
}
