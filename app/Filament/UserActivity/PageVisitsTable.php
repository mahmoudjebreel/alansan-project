<?php

namespace App\Filament\UserActivity;

use App\Models\User;
use App\Models\UserPageVisit;
use App\Support\Activity\Duration;
use App\Support\Activity\PageLabels;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every page load, with the approximate time the tab stayed visible on it.
 *
 * Embedded by the User Activity page; not a discovered widget. When the page
 * passes a session id the listing is narrowed to that one sitting.
 */
class PageVisitsTable extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public ?int $sessionId = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('ui.user_activity.tabs.visits'))
            ->description(__('ui.user_activity.approximate'))
            ->query(
                UserPageVisit::query()
                    ->with('user')
                    ->when($this->sessionId, fn (Builder $query, int $sessionId) => $query->where('user_session_id', $sessionId)),
            )
            ->defaultSort('entered_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('ui.user_activity.columns.user'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('route_name')
                    ->label(__('ui.user_activity.columns.page'))
                    ->formatStateUsing(fn (?string $state, UserPageVisit $record): string => PageLabels::labelFor($state, $record->page_kind))
                    ->description(fn (UserPageVisit $record): string => $record->path)
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label(__('ui.user_activity.columns.record'))
                    ->formatStateUsing(fn (?string $state, UserPageVisit $record): string => $state !== null
                        ? class_basename($state) . ($record->subject_id !== null ? " #{$record->subject_id}" : '')
                        : '-')
                    ->placeholder('-'),
                TextColumn::make('ip_address')
                    ->label(__('ui.user_activity.columns.ip'))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('entered_at')
                    ->label(__('ui.user_activity.columns.entered_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('ended_at')
                    ->label(__('ui.user_activity.columns.ended_at'))
                    ->state(fn (UserPageVisit $record) => $record->endedAt())
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder(__('ui.user_activity.columns.open')),
                TextColumn::make('active_seconds')
                    ->label(__('ui.user_activity.columns.active_time'))
                    ->formatStateUsing(fn (?int $state): string => Duration::humanize($state))
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('ui.user_activity.filters.user'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('page_kind')
                    ->label(__('ui.user_activity.filters.page_kind'))
                    ->options([
                        'dashboard' => __('ui.user_activity.kinds.dashboard'),
                        'list' => __('ui.user_activity.kinds.list'),
                        'view' => __('ui.user_activity.kinds.view'),
                        'edit' => __('ui.user_activity.kinds.edit'),
                        'create' => __('ui.user_activity.kinds.create'),
                        'page' => __('ui.user_activity.kinds.page'),
                    ]),
                Filter::make('entered_between')
                    ->schema([
                        DatePicker::make('from')->label(__('ui.user_activity.filters.from')),
                        DatePicker::make('until')->label(__('ui.user_activity.filters.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('entered_at', '>=', $from))
                            ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->whereDate('entered_at', '<=', $until));
                    }),
            ])
            ->emptyStateHeading(__('ui.user_activity.empty.visits'));
    }
}
