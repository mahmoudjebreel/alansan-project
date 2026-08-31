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
 * it would tie the two together. It sits in the same "إدارة النظام" group,
 * directly under the general settings.
 */
class NotificationSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'إعدادات الإشعارات';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $title = 'إعدادات الإشعارات';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.notification-settings-page';

    public ?array $data = [];

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
                \Filament\Schemas\Components\Section::make('تشغيل الإشعارات')
                    ->description('مفتاح رئيسي لإيقاف أو تشغيل نظام الإشعارات بالكامل')
                    ->icon('heroicon-o-power')
                    ->schema([
                        Forms\Components\Toggle::make('enabled')
                            ->label('تفعيل نظام الإشعارات')
                            ->helperText('عند الإيقاف لن يتم إرسال أي إشعار، مع بقاء السجل السابق كما هو.')
                            ->extraAttributes(['aria-label' => 'تفعيل نظام الإشعارات']),
                        Forms\Components\Toggle::make('notify_self_actions')
                            ->label('تضمين إجراءات مدير النظام (Super Admin) في الإشعارات')
                            ->helperText('م مفيد للتجربة والاختبار: عند التفعيل يتم إرسال إشعار حتى عند قيام مدير النظام بالإجراء بنفسه.')
                            ->extraAttributes(['aria-label' => 'تضمين إجراءات مدير النظام']),
                    ]),

                \Filament\Schemas\Components\Section::make('أنواع الإجراءات')
                    ->description('اختر الإجراءات التي تُرسل إشعاراً')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        Forms\Components\CheckboxList::make('enabled_actions')
                            ->label('الإجراءات المفعّلة')
                            ->hiddenLabel()
                            ->options(ActionType::options())
                            ->columns(3)
                            ->bulkToggleable(),
                    ]),

                \Filament\Schemas\Components\Section::make('المستلمون')
                    ->description('حدد مديري النظام الذين يستلمون الإشعارات')
                    ->icon('heroicon-o-user-group')
                    ->schema([
                        Forms\Components\Select::make('recipient_user_ids')
                            ->label('المستلمون')
                            ->helperText('اتركه فارغاً لإرسال الإشعارات إلى جميع مديري النظام.')
                            ->multiple()
                            ->options(fn (): array => User::role('Super Admin')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->native(false),
                    ]),

                \Filament\Schemas\Components\Section::make('تجميع الإشعارات')
                    ->description('دمج الإجراءات المتتابعة لنفس المستخدم في إشعار واحد')
                    ->icon('heroicon-o-rectangle-stack')
                    ->schema([
                        Forms\Components\TextInput::make('group_window_seconds')
                            ->label('مدة التجميع (بالثواني)')
                            ->helperText('مثال: 60 يعني دمج إضافات نفس المستخدم خلال دقيقة في إشعار واحد. الصفر يعني إرسال كل إجراء على حدة.')
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
                ->label('حفظ الإعدادات')
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
                        ->title('تم حفظ إعدادات الإشعارات')
                        ->success()
                        ->send();
                }),
        ];
    }
}
