<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\UserPageVisit;
use App\Support\Activity\Duration;
use App\Support\Activity\PageLabels;
use App\Support\Activity\TelemetryPruner;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * User activity monitoring: who is online, every sitting, every page load,
 * and a per-user timeline that merges page visits with the rows the
 * existing Activity Log already holds for that user.
 *
 * Read-only apart from the prune button, which deletes telemetry older than
 * the retention period and never touches activity_log. Gated by its own
 * permission; Super Admin only unless an operator grants it further.
 */
class UserActivityPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-eye';

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.pages.user-activity-page';

    public const TABS = ['online', 'sessions', 'visits', 'timeline'];

    #[Url]
    public string $activeTab = 'online';

    /** Narrows the visits tab to one sitting, set from the sessions listing. */
    #[Url]
    public ?int $sessionId = null;

    /** @var array<string, mixed> Timeline filters. */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('ui.user_activity.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.system');
    }

    public function getTitle(): string
    {
        return __('ui.user_activity.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('user_activity.view') ?? false;
    }

    public function mount(): void
    {
        if (! in_array($this->activeTab, self::TABS, true)) {
            $this->activeTab = 'online';
        }

        $this->form->fill([
            'user_id' => null,
            'from' => now()->subDays(7)->toDateString(),
            'until' => now()->toDateString(),
        ]);
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true)) {
            return;
        }

        $this->activeTab = $tab;

        if ($tab !== 'visits') {
            $this->sessionId = null;
        }
    }

    #[On('user-activity.show-visits')]
    public function showVisits(int $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->activeTab = 'visits';
    }

    public function clearSession(): void
    {
        $this->sessionId = null;
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public function tabs(): array
    {
        return [
            'online' => ['label' => __('ui.user_activity.tabs.online'), 'icon' => 'heroicon-m-signal'],
            'sessions' => ['label' => __('ui.user_activity.tabs.sessions'), 'icon' => 'heroicon-m-computer-desktop'],
            'visits' => ['label' => __('ui.user_activity.tabs.visits'), 'icon' => 'heroicon-m-document-text'],
            'timeline' => ['label' => __('ui.user_activity.tabs.timeline'), 'icon' => 'heroicon-m-clock'],
        ];
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Grid::make(3)->schema([
                    Select::make('user_id')
                        ->label(__('ui.user_activity.timeline.user'))
                        ->placeholder(__('ui.user_activity.timeline.select_user'))
                        ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->native(false),
                    DatePicker::make('from')
                        ->label(__('ui.user_activity.timeline.from')),
                    DatePicker::make('until')
                        ->label(__('ui.user_activity.timeline.until')),
                ]),
            ])
            ->statePath('data');
    }

    /**
     * The selected user's page visits and activity_log rows, newest first.
     *
     * @return Collection<int, array{at: Carbon, type: string, title: string, meta: array<string, string>, color: string, icon: string}>
     */
    public function timelineEvents(): Collection
    {
        $userId = $this->data['user_id'] ?? null;

        if (! is_numeric($userId)) {
            return collect();
        }

        $userId = (int) $userId;
        $from = $this->boundary($this->data['from'] ?? null, now()->subDays(7))->startOfDay();
        $until = $this->boundary($this->data['until'] ?? null, now())->endOfDay();
        $limit = max(50, (int) config('user-activity.timeline_limit', 300));

        $visits = UserPageVisit::query()
            ->where('user_id', $userId)
            ->whereBetween('entered_at', [$from, $until])
            ->orderByDesc('entered_at')
            ->limit($limit)
            ->get()
            ->map(fn (UserPageVisit $visit): array => [
                'at' => $visit->entered_at,
                'type' => 'visit',
                'title' => PageLabels::labelFor($visit->route_name, $visit->page_kind),
                'meta' => array_filter([
                    __('ui.user_activity.columns.record') => $visit->subject_type !== null
                        ? class_basename($visit->subject_type) . ($visit->subject_id !== null ? " #{$visit->subject_id}" : '')
                        : null,
                    __('ui.user_activity.columns.active_time') => Duration::humanize($visit->active_seconds),
                    __('ui.user_activity.columns.ip') => $visit->ip_address,
                ]),
                'color' => 'info',
                'icon' => 'heroicon-m-document-text',
            ]);

        $activities = Activity::query()
            ->where('causer_type', User::class)
            ->where('causer_id', $userId)
            ->whereBetween('created_at', [$from, $until])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $activity): array => $this->activityEvent($activity));

        return $visits
            ->concat($activities)
            ->sortByDesc(fn (array $event) => $event['at']->getTimestamp())
            ->take($limit)
            ->values();
    }

    /**
     * @return array{at: Carbon, type: string, title: string, meta: array<string, string>, color: string, icon: string}
     */
    private function activityEvent(Activity $activity): array
    {
        $properties = $activity->properties ?? collect();
        $changed = $properties->get('attributes');
        $subject = $activity->subject_type !== null
            ? class_basename($activity->subject_type) . ($activity->subject_id !== null ? " #{$activity->subject_id}" : '')
            : null;

        $meta = array_filter([
            __('ui.activity_log.log_name') => $activity->log_name,
            __('ui.user_activity.timeline.event') => $activity->event,
            __('ui.user_activity.columns.record') => $subject,
            __('ui.user_activity.timeline.changed_fields') => is_array($changed) && $changed !== []
                ? implode(', ', array_slice(array_keys($changed), 0, 12))
                : null,
            __('ui.user_activity.columns.ip') => is_string($properties->get('ip')) ? $properties->get('ip') : null,
        ]);

        [$color, $icon] = match ($activity->log_name) {
            'auth' => [$activity->event === 'login_failed' ? 'danger' : 'success', 'heroicon-m-key'],
            'excel', 'export' => ['warning', 'heroicon-m-arrow-down-tray'],
            'backup' => ['gray', 'heroicon-m-circle-stack'],
            'referral' => ['primary', 'heroicon-m-arrow-right-circle'],
            'bulk' => ['danger', 'heroicon-m-trash'],
            default => match ($activity->event) {
                'created' => ['success', 'heroicon-m-plus-circle'],
                'deleted' => ['danger', 'heroicon-m-trash'],
                'restored' => ['info', 'heroicon-m-arrow-uturn-left'],
                default => ['primary', 'heroicon-m-pencil-square'],
            },
        };

        return [
            'at' => $activity->created_at,
            'type' => 'activity',
            'title' => (string) $activity->description,
            'meta' => $meta,
            'color' => $color,
            'icon' => $icon,
        ];
    }

    private function boundary(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || $value === '') {
            return $fallback->copy();
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return $fallback->copy();
        }
    }

    protected function getHeaderActions(): array
    {
        $days = max(1, (int) config('user-activity.retention_days', 90));

        return [
            Action::make('prune')
                ->label(__('ui.user_activity.actions.prune'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('ui.user_activity.actions.prune_heading'))
                ->modalDescription(__('ui.user_activity.actions.prune_description', ['days' => $days]))
                ->visible(fn (): bool => auth()->user()?->hasRole('Super Admin') ?? false)
                ->action(function () use ($days): void {
                    $result = TelemetryPruner::prune($days);

                    Notification::make()
                        ->title(__('ui.user_activity.actions.prune_done', [
                            'visits' => $result['visits'],
                            'sessions' => $result['sessions'],
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
