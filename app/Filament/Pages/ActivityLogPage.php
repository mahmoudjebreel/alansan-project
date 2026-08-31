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

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $navigationLabel = 'سجل النشاطات';

    protected static ?string $title = 'سجل النشاطات';

    protected string $view = 'filament.pages.activity-log-page';

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
                    ->label('اسم السجل')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('description')
                    ->label('الوصف')
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('نوع الكائن')
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject_id')
                    ->label('معرف الكائن')
                    ->searchable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('الفاعل')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
