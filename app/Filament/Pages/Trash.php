<?php

namespace App\Filament\Pages;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

class Trash extends Page
{
    use WithPagination;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-trash';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $navigationLabel = 'سلة المحذوفات';

    protected static ?string $title = 'سلة المحذوفات';

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.trash';

    /**
     * Number of records shown per page.
     */
    public int $perPage = 25;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('trash.view') ?? false;
    }

    /**
     * Central registry of every soft-deletable module surfaced in the Trash.
     *
     * Each entry describes how to label a record, pull its key identifier, and
     * derive a human-readable name. Adding a new module is a single array entry.
     *
     * @return array<string, array{model: class-string<Model>, label: string, name: callable, identifier: callable}>
     */
    public static function modules(): array
    {
        return [
            'child' => [
                'model' => Child::class,
                'label' => 'الأطفال',
                'name' => fn (Model $record): ?string => $record->name,
                'identifier' => fn (Model $record): ?string => $record->child_id,
            ],
            'pregnant_lactating_woman' => [
                'model' => PregnantLactatingWoman::class,
                'label' => 'الحوامل والمرضعات',
                'name' => fn (Model $record): ?string => $record->full_name_ar,
                'identifier' => fn (Model $record): ?string => $record->mother_id,
            ],
            'individual_counseling' => [
                'model' => IndividualCounseling::class,
                'label' => 'الاستشارة الفردية',
                'name' => fn (Model $record): ?string => $record->mother_name ?: $record->child_name,
                'identifier' => fn (Model $record): ?string => $record->mother_id_number,
            ],
            'mother_to_mother' => [
                'model' => MotherToMotherSession::class,
                'label' => 'الأم للأم',
                'name' => fn (Model $record): ?string => $record->full_name_ar,
                'identifier' => fn (Model $record): ?string => $record->id_number,
            ],
            'group_session' => [
                'model' => GroupSession::class,
                'label' => 'الجلسات الجماعية',
                'name' => fn (Model $record): ?string => $record->full_name_ar,
                'identifier' => fn (Model $record): ?string => $record->id_number,
            ],
            // Follow Up Child is soft-deletable and its list has a bulk Delete,
            // but it was missing from this registry: a deleted record was
            // invisible everywhere and could never be restored.
            'follow_up_child' => [
                'model' => FollowUpChild::class,
                'label' => 'متابعة الأطفال',
                'name' => fn (Model $record): ?string => $record->child_name,
                'identifier' => fn (Model $record): ?string => $record->id_number,
            ],
        ];
    }

    /**
     * Build the unified, paginated list of soft-deleted records across modules.
     */
    public function getRows(): LengthAwarePaginator
    {
        $rows = collect();

        foreach (static::modules() as $type => $config) {
            /** @var class-string<Model> $model */
            $model = $config['model'];

            $records = $model::onlyTrashed()
                ->orderByDesc('deleted_at')
                ->get();

            if ($records->isEmpty()) {
                continue;
            }

            $deleters = $this->resolveDeleters($model, $records->pluck('id')->all());

            foreach ($records as $record) {
                $rows->push([
                    'type' => $type,
                    'module' => $config['label'],
                    'id' => $record->getKey(),
                    'name' => ($config['name'])($record),
                    'identifier' => ($config['identifier'])($record),
                    'deleted_at' => $record->deleted_at,
                    'deleted_by' => $deleters[$record->getKey()] ?? null,
                ]);
            }
        }

        $sorted = $rows
            ->sortByDesc(fn (array $row) => optional($row['deleted_at'])->timestamp ?? 0)
            ->values();

        $page = $this->getPage();
        $items = $sorted->forPage($page, $this->perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $sorted->count(),
            $this->perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    /**
     * Map each record id to the name of the user who deleted it, using the
     * activity log. Runs one query per module rather than one per record.
     *
     * @param  class-string<Model>  $model
     * @param  array<int>  $ids
     * @return Collection<int, string>
     */
    protected function resolveDeleters(string $model, array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        return Activity::query()
            ->where('subject_type', $model)
            ->where('event', 'deleted')
            ->whereIn('subject_id', $ids)
            ->with('causer')
            ->latest()
            ->get()
            ->groupBy('subject_id')
            ->map(fn (Collection $activities) => $activities->first()?->causer?->name)
            ->filter();
    }

    /**
     * Restore a soft-deleted record back to its original table.
     *
     * Returns true on success; the front-end uses this to show the toast.
     */
    public function restore(string $type, int $id): bool
    {
        abort_unless(auth()->user()?->can('trash.restore') ?? false, 403);

        $record = $this->findTrashed($type, $id);

        if (! $record) {
            return false;
        }

        $record->restore();

        $this->resetPage();

        return true;
    }

    /**
     * Permanently delete a soft-deleted record. This cannot be undone.
     *
     * Returns true on success; the front-end uses this to show the toast.
     */
    public function forceDelete(string $type, int $id): bool
    {
        abort_unless(auth()->user()?->can('trash.force_delete') ?? false, 403);

        $record = $this->findTrashed($type, $id);

        if (! $record) {
            return false;
        }

        $record->forceDelete();

        $this->resetPage();

        return true;
    }

    /**
     * Locate a trashed record for a given module type, guarding against
     * unknown types and records that are not actually soft-deleted.
     */
    protected function findTrashed(string $type, int $id): ?Model
    {
        $config = static::modules()[$type] ?? null;

        if (! $config) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = $config['model'];

        // Only operate on models that actually support soft deletes.
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return null;
        }

        $record = $model::onlyTrashed()->find($id);

        return $record?->trashed() ? $record : null;
    }
}
