<?php

namespace App\Filament\Pages;

use App\Exports\NotificationLogExport;
use App\Notifications\DataActionNotification;
use App\Support\Notifications\ActionType;
use App\Support\Notifications\NotifiableModule;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read and unread history of the Super Admin data-action notifications.
 *
 * Scoped to DataActionNotification, so notifications the panel sends for other
 * reasons never show up here.
 */
class NotificationLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.notification-log-page';

    public static function getNavigationLabel(): string
    {
        return __('ui.notification_log.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.system');
    }

    public function getTitle(): string
    {
        return __('ui.notification_log.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('notifications.manage') ?? false;
    }

    public static function baseQuery(): Builder
    {
        return DatabaseNotification::query()
            ->where('type', DataActionNotification::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::baseQuery())
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.notification_log.datetime'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('data.action_type')
                    ->label(__('ui.notification_log.action_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? ActionType::title($state) : '-')
                    ->color(fn (?string $state): string => filled($state) ? ActionType::color($state) : 'gray'),
                Tables\Columns\TextColumn::make('data.module_label')
                    ->label(__('ui.notification_log.module')),
                Tables\Columns\TextColumn::make('data.actor_name')
                    ->label(__('ui.notification_log.actor')),
                Tables\Columns\TextColumn::make('data.actor_role')
                    ->label(__('ui.notification_log.role'))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('data.record_label')
                    ->label(__('ui.notification_log.record'))
                    ->placeholder('-')
                    ->wrap(),
                Tables\Columns\TextColumn::make('data.record_count')
                    ->label(__('ui.notification_log.record_count'))
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('data.priority')
                    ->label(__('ui.notification_log.priority'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'high' => __('ui.notification_log.priority_high'),
                        'medium' => __('ui.notification_log.priority_medium'),
                        'low' => __('ui.notification_log.priority_low'),
                        default => '-',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'high' => 'danger',
                        'medium' => 'warning',
                        'low' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('read_at')
                    ->label(__('ui.notification_log.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => filled($state) ? __('ui.notification_log.read') : __('ui.notification_log.unread'))
                    ->color(fn ($state): string => filled($state) ? 'gray' : 'info'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('action_type')
                    ->label(__('ui.notification_log.action_type'))
                    ->options(ActionType::options())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $value): Builder => $query->where('data->action_type', $value),
                    )),
                Tables\Filters\SelectFilter::make('module')
                    ->label(__('ui.notification_log.module'))
                    ->options(NotifiableModule::options())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $value): Builder => $query->where('data->module', $value),
                    )),
                Tables\Filters\SelectFilter::make('actor')
                    ->label(__('ui.notification_log.actor'))
                    ->options(fn (): array => static::actorOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $value): Builder => $query->where('data->actor_name', $value),
                    )),
                Tables\Filters\Filter::make('occurred_at')
                    ->label(__('ui.notification_log.date'))
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('ui.notification_log.from')),
                        Forms\Components\DatePicker::make('until')->label(__('ui.notification_log.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->headerActions([
                Action::make('exportExcel')
                    ->label(__('ui.notification_log.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (): BinaryFileResponse => $this->downloadExcel()),
            ]);
    }

    /**
     * Export exactly what the table currently shows, filters included.
     */
    public function downloadExcel(): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('notifications.manage'), 403);

        return Excel::download(
            new NotificationLogExport($this->getFilteredSortedTableQuery()),
            'notification-log.xlsx',
        );
    }

    /**
     * Actors that actually appear in the log, so the filter never offers a
     * name with no rows behind it.
     *
     * @return array<string, string>
     */
    private static function actorOptions(): array
    {
        return static::baseQuery()
            ->get(['data'])
            ->map(fn (DatabaseNotification $n): ?string => $n->data['actor_name'] ?? null)
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $name): array => [$name => $name])
            ->all();
    }
}
