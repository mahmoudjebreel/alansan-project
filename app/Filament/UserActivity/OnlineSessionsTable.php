<?php

namespace App\Filament\UserActivity;

use App\Models\UserSession;
use App\Support\Activity\PageLabels;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Who is in the panel right now, and on which page.
 *
 * Embedded by the User Activity page; not a discovered widget, so it never
 * appears on the dashboard. Refreshes itself every half minute.
 */
class OnlineSessionsTable extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('ui.user_activity.tabs.online'))
            ->description(__('ui.user_activity.online_hint', ['minutes' => UserSession::onlineWithinMinutes()]))
            ->query(
                UserSession::query()
                    ->online()
                    ->with(['user', 'latestVisit'])
                    ->orderByDesc('last_seen_at'),
            )
            ->poll('30s')
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('ui.user_activity.columns.user'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('latestVisit.route_name')
                    ->label(__('ui.user_activity.columns.current_page'))
                    ->formatStateUsing(fn (?string $state, UserSession $record): string => PageLabels::labelFor($state, $record->latestVisit?->page_kind))
                    ->placeholder('-'),
                TextColumn::make('ip_address')
                    ->label(__('ui.user_activity.columns.ip'))
                    ->placeholder('-'),
                TextColumn::make('device')
                    ->label(__('ui.user_activity.columns.device'))
                    ->state(fn (UserSession $record): string => $record->deviceLabel()),
                TextColumn::make('login_at')
                    ->label(__('ui.user_activity.columns.login_at'))
                    ->dateTime('Y-m-d H:i'),
                TextColumn::make('last_seen_at')
                    ->label(__('ui.user_activity.columns.last_seen_at'))
                    ->since(),
            ])
            ->emptyStateHeading(__('ui.user_activity.empty.online'))
            ->emptyStateIcon('heroicon-o-signal-slash');
    }
}
