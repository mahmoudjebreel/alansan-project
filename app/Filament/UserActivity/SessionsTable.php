<?php

namespace App\Filament\UserActivity;

use App\Models\User;
use App\Models\UserSession;
use App\Support\Activity\PageLabels;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every sitting on record: who signed in, from where, on what, and when
 * they were last seen or signed out.
 *
 * Embedded by the User Activity page; not a discovered widget.
 */
class SessionsTable extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('ui.user_activity.tabs.sessions'))
            ->query(
                UserSession::query()
                    ->with(['user', 'latestVisit'])
                    ->withCount('visits'),
            )
            ->defaultSort('login_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('ui.user_activity.columns.user'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('status')
                    ->label(__('ui.user_activity.columns.status'))
                    ->badge()
                    ->state(fn (UserSession $record): string => $record->status())
                    ->formatStateUsing(fn (string $state): string => __("ui.user_activity.status.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        UserSession::STATUS_ONLINE => 'success',
                        UserSession::STATUS_IDLE => 'warning',
                        UserSession::STATUS_LOGGED_OUT => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('latestVisit.route_name')
                    ->label(__('ui.user_activity.columns.current_page'))
                    ->formatStateUsing(fn (?string $state, UserSession $record): string => PageLabels::labelFor($state, $record->latestVisit?->page_kind))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('ip_address')
                    ->label(__('ui.user_activity.columns.ip'))
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('device')
                    ->label(__('ui.user_activity.columns.device'))
                    ->state(fn (UserSession $record): string => $record->deviceLabel())
                    ->tooltip(fn (UserSession $record): ?string => $record->user_agent),
                IconColumn::make('remember')
                    ->label(__('ui.user_activity.columns.remember'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('login_at')
                    ->label(__('ui.user_activity.columns.login_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label(__('ui.user_activity.columns.last_seen_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('logout_at')
                    ->label(__('ui.user_activity.columns.logout_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('visits_count')
                    ->label(__('ui.user_activity.columns.visits'))
                    ->alignCenter()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('ui.user_activity.filters.user'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('status')
                    ->label(__('ui.user_activity.filters.status'))
                    ->options([
                        UserSession::STATUS_ONLINE => __('ui.user_activity.status.online'),
                        UserSession::STATUS_IDLE => __('ui.user_activity.status.idle'),
                        UserSession::STATUS_EXPIRED => __('ui.user_activity.status.expired'),
                        UserSession::STATUS_LOGGED_OUT => __('ui.user_activity.status.logged_out'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyStatus($query, $data['value'] ?? null)),
                Filter::make('login_between')
                    ->schema([
                        DatePicker::make('from')->label(__('ui.user_activity.filters.from')),
                        DatePicker::make('until')->label(__('ui.user_activity.filters.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('login_at', '>=', $from))
                            ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->whereDate('login_at', '<=', $until));
                    }),
            ])
            ->recordActions([
                Action::make('visits')
                    ->label(__('ui.user_activity.actions.show_visits'))
                    ->icon('heroicon-o-list-bullet')
                    ->action(fn (UserSession $record) => $this->dispatch('user-activity.show-visits', sessionId: $record->getKey())),
            ])
            ->emptyStateHeading(__('ui.user_activity.empty.sessions'));
    }

    private static function applyStatus(Builder $query, ?string $status): Builder
    {
        $onlineSince = now()->subMinutes(UserSession::onlineWithinMinutes());
        $lifetimeSince = now()->subMinutes(max(1, (int) config('session.lifetime', 120)));

        return match ($status) {
            UserSession::STATUS_ONLINE => $query->whereNull('logout_at')->where('last_seen_at', '>=', $onlineSince),
            UserSession::STATUS_IDLE => $query->whereNull('logout_at')
                ->where('last_seen_at', '<', $onlineSince)
                ->where('last_seen_at', '>=', $lifetimeSince),
            UserSession::STATUS_EXPIRED => $query->whereNull('logout_at')->where('last_seen_at', '<', $lifetimeSince),
            UserSession::STATUS_LOGGED_OUT => $query->whereNotNull('logout_at'),
            default => $query,
        };
    }
}
