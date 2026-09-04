<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Table;
use Filament\Tables;
use Spatie\Activitylog\Models\Activity;

class ActivityLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected string $view = 'filament.pages.activity-log-page';

    public static function getNavigationLabel(): string
    {
        return __('ui.activity_log.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.system');
    }

    public function getTitle(): string
    {
        return __('ui.activity_log.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('activity.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()->latest())
            ->columns([
                Tables\Columns\TextColumn::make('log_name')
                    ->label(__('ui.activity_log.log_name'))
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('ui.activity_log.description'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label(__('ui.activity_log.subject_type'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject_id')
                    ->label(__('ui.activity_log.subject_id'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label(__('ui.activity_log.causer'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.activity_log.created_at'))
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
