<?php

namespace App\Filament\Pages;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use App\Support\BulkRecordWriter;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

class Trash extends Page
{
    use WithPagination;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-trash';

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.trash';

    public static function getNavigationLabel(): string
    {
        return __('ui.trash.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.trash');
    }

    public function getTitle(): string
    {
        return __('ui.trash.title');
    }

    /**
     * Number of records shown per page.
     */
    public int $perPage = 25;

    /**
     * Totals for the header cards, filled in by getRows().
     *
     * Livewire round-trips every public property, so the timestamp is kept as
     * an already-formatted string rather than a Carbon instance.
     *
     * @var array{total: int, modules: int, latest: string|null}
     */
    public array $summary = ['total' => 0, 'modules' => 0, 'latest' => null];

    /**
     * The rows ticked for a bulk action, as "module:id" keys.
     *
     * A key rather than a bare id because ids repeat across modules: child 7
     * and follow-up child 7 are different records in different tables.
     *
     * @var array<int, string>
     */
    public array $selected = [];

    /**
     * Whether "everything in the trash" is selected, not just the ticked page.
     *
     * A flag, not a list: the trash can hold tens of thousands of keys and the
     * listing was deliberately rebuilt so that nothing ever reads them all into
     * memory. A bulk action under this flag runs one set-based statement per
     * module instead.
     */
    public bool $selectingAll = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('trash.view') ?? false;
    }

    /**
     * Central registry of every soft-deletable module surfaced in the Trash.
     *
     * Each entry describes how to label a record, pull its key identifier,
     * derive a human-readable name, and which icon and colour stand for the
     * module in the listing. Adding a new module is a single array entry - the
     * view reads the badge straight off it, so a module can no longer be added
     * here and come out grey because a colour map elsewhere was not updated.
     *
     * @return array<string, array{model: class-string<Model>, label: string, icon: string, color: string, name: callable, identifier: callable}>
     */
    public static function modules(): array
    {
        return [
            'child' => [
                'model' => Child::class,
                'label' => __('ui.modules.child'),
                'icon' => 'heroicon-o-face-smile',
                'color' => 'primary',
                'name' => fn (Model $record): ?string => $record->name,
                'identifier' => fn (Model $record): ?string => $record->child_id,
            ],
            'pregnant_lactating_woman' => [
                'model' => PregnantLactatingWoman::class,
                'label' => __('ui.modules.pregnant_lactating_woman'),
                'icon' => 'heroicon-o-heart',
                'color' => 'info',
                'name' => fn (Model $record): ?string => $record->full_name_ar,
                'identifier' => fn (Model $record): ?string => $record->mother_id,
            ],
            'individual_counseling' => [
                'model' => IndividualCounseling::class,
                'label' => __('ui.modules.individual_counseling'),
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'color' => 'warning',
                'name' => fn (Model $record): ?string => $record->mother_name ?: $record->child_name,
                'identifier' => fn (Model $record): ?string => $record->mother_id_number,
            ],
            'mother_to_mother' => [
                'model' => MotherToMotherSession::class,
                'label' => __('ui.modules.mother_to_mother'),
                'icon' => 'heroicon-o-users',
                'color' => 'success',
                'name' => fn (Model $record): ?string => $record->full_name_ar,
                'identifier' => fn (Model $record): ?string => $record->id_number,
            ],
            'group_session' => [
                'model' => GroupSession::class,
                'label' => __('ui.modules.group_session'),
                'icon' => 'heroicon-o-user-group',
                'color' => 'gray',
                'name' => fn (Model $record): ?string => $record->full_name_ar,
                'identifier' => fn (Model $record): ?string => $record->id_number,
            ],
            'follow_up_child' => [
                'model' => FollowUpChild::class,
                'label' => __('ui.modules.follow_up_child'),
                'icon' => 'heroicon-o-clipboard-document-check',
                'color' => 'danger',
                'name' => fn (Model $record): ?string => $record->child_name,
                'identifier' => fn (Model $record): ?string => $record->id_number,
            ],
        ];
    }

    /**
     * Build the unified, paginated list of soft-deleted records across modules.
     *
     * The database does the sorting and the paging, not PHP. The previous
     * version read every trashed row of all six modules into memory on every
     * page view - `select * from children where deleted_at is not null`, with
     * no limit, six times - then sorted the lot and sliced twenty-five rows out
     * of it. The query count stayed flat, which is why nothing looked wrong,
     * but the work behind each query grew with the size of the trash: at fifty
     * thousand deleted records the page hydrated fifty thousand Eloquent models
     * and built an `IN` clause with fifty thousand ids in it, to show twenty
     * five rows.
     *
     * Now a UNION over the six tables - each contributing only a module tag, a
     * key and a timestamp - is ordered and paged by the database, using the
     * `deleted_at` index each table already has. Only the keys on the page are
     * then read back as models. Everything here is constant work, whatever the
     * trash holds.
     */
    public function getRows(): LengthAwarePaginator
    {
        $summary = $this->summarise();

        $this->summary = $summary;

        $page = $this->getPage();
        $keys = $this->keysOnPage($page);

        return new LengthAwarePaginator(
            $this->recordsFor($keys),
            $summary['total'],
            $this->perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    /**
     * The trash as one list of (module, key, deleted_at), before paging.
     *
     * Deliberately not a list of records: the point is that this can be
     * ordered and sliced by the database without reading a single row of
     * anybody's data.
     */
    protected function indexQuery(): Builder
    {
        $union = null;

        foreach (static::modules() as $type => $config) {
            /** @var class-string<Model> $model */
            $model = $config['model'];
            $table = (new $model)->getTable();

            // $type is a key of this class's own registry - never user input -
            // so it is safe to inline, and inlining keeps the binding order of
            // the UNION straightforward.
            $part = DB::table($table)
                ->selectRaw("'" . $type . "' as module_type, id, deleted_at")
                ->whereNotNull('deleted_at');

            $union = $union === null ? $part : $union->unionAll($part);
        }

        return $union;
    }

    /**
     * Totals for the header cards, in one grouped query over the index.
     *
     * @return array{total: int, modules: int, latest: string|null}
     */
    protected function summarise(): array
    {
        $groups = DB::query()
            ->fromSub($this->indexQuery(), 'trash')
            ->selectRaw('module_type, count(*) as total, max(deleted_at) as latest')
            ->groupBy('module_type')
            ->get();

        $latest = $groups->pluck('latest')->filter()->max();

        return [
            'total' => (int) $groups->sum('total'),
            'modules' => $groups->count(),
            'latest' => $latest ? Carbon::parse($latest)->format('Y-m-d H:i') : null,
        ];
    }

    /**
     * The (module, key) pairs on one page, newest deletion first.
     *
     * The module and key tie-breakers keep the order stable: without them two
     * records deleted in the same second could swap places between page one
     * and page two and one of them would never be shown.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function keysOnPage(int $page): Collection
    {
        return DB::query()
            ->fromSub($this->indexQuery(), 'trash')
            ->orderByDesc('deleted_at')
            ->orderBy('module_type')
            ->orderByDesc('id')
            ->forPage($page, $this->perPage)
            ->get();
    }

    /**
     * Read the records for one page of keys, one query per module present.
     *
     * Not called hydrate(): that is a Livewire lifecycle hook, and Livewire
     * calls it with no arguments on every request.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $keys
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function recordsFor(Collection $keys): Collection
    {
        $records = [];
        $deleters = [];

        foreach ($keys->groupBy('module_type') as $type => $entries) {
            $config = static::modules()[$type] ?? null;

            if (! $config) {
                continue;
            }

            /** @var class-string<Model> $model */
            $model = $config['model'];
            $ids = $entries->pluck('id')->all();

            foreach ($model::onlyTrashed()->whereIn('id', $ids)->get() as $record) {
                $records[$type . ':' . $record->getKey()] = $record;
            }

            foreach ($this->resolveDeleters($model, $ids) as $id => $name) {
                $deleters[$type . ':' . $id] = $name;
            }
        }

        return $keys
            ->map(function (object $entry) use ($records, $deleters): ?array {
                $key = $entry->module_type . ':' . $entry->id;
                $record = $records[$key] ?? null;

                // Force-deleted between the index query and this one. Skipping
                // it is right: it is not in the trash any more.
                if (! $record) {
                    return null;
                }

                $config = static::modules()[$entry->module_type];

                return [
                    'type' => $entry->module_type,
                    'module' => $config['label'],
                    'icon' => $config['icon'],
                    'color' => $config['color'],
                    'id' => $record->getKey(),
                    'name' => ($config['name'])($record),
                    'identifier' => ($config['identifier'])($record),
                    'deleted_at' => $record->deleted_at,
                    'deleted_by' => $deleters[$key] ?? null,
                ];
            })
            ->filter()
            ->values();
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
     * The keys of the rows on the current page, in listing order.
     *
     * @return array<int, string>
     */
    public function pageKeys(): array
    {
        return $this->keysOnPage($this->getPage())
            ->map(fn (object $entry): string => $entry->module_type . ':' . $entry->id)
            ->all();
    }

    /**
     * Whether every row on the current page is ticked - what drives the
     * header checkbox.
     */
    public function isPageSelected(): bool
    {
        $keys = $this->pageKeys();

        return $keys !== [] && array_diff($keys, $this->selected) === [];
    }

    /**
     * How many records a bulk action would touch right now.
     */
    public function selectedCount(): int
    {
        return $this->selectingAll ? $this->summary['total'] : count($this->selected);
    }

    /**
     * Tick every row on the current page (the header checkbox).
     */
    public function selectPage(): void
    {
        $this->selected = array_values(array_unique([...$this->selected, ...$this->pageKeys()]));
    }

    /**
     * Extend a fully ticked page to the whole trash, across every page.
     */
    public function selectAll(): void
    {
        $this->selectPage();
        $this->selectingAll = true;
    }

    public function deselectAll(): void
    {
        $this->selected = [];
        $this->selectingAll = false;
    }

    /**
     * Un-ticking any single row ends an "everything" selection: the user has
     * just said one record is not included.
     */
    public function updatedSelected(): void
    {
        $this->selected = array_values(array_unique(array_filter($this->selected, 'is_string')));

        if ($this->selectingAll && ! $this->isPageSelected()) {
            $this->selectingAll = false;
        }
    }

    /**
     * Moving to another page drops the selection. Keeping keys from a page
     * that is no longer visible would make the bulk buttons act on rows the
     * user cannot see.
     */
    public function updatedPaginators(): void
    {
        $this->deselectAll();
    }

    /**
     * Restore every selected record.
     *
     * Returns true on success; the front-end uses this to show the toast.
     */
    public function restoreSelected(): bool
    {
        abort_unless(auth()->user()?->can('trash.restore') ?? false, 403);

        return $this->applyToSelection(fn (EloquentBuilder $query): int => BulkRecordWriter::restore($query));
    }

    /**
     * Permanently delete every selected record. This cannot be undone.
     *
     * Returns true on success; the front-end uses this to show the toast.
     */
    public function forceDeleteSelected(): bool
    {
        abort_unless(auth()->user()?->can('trash.force_delete') ?? false, 403);

        return $this->applyToSelection(fn (EloquentBuilder $query): int => BulkRecordWriter::forceDelete($query));
    }

    /**
     * Run one set-based write per module over whatever is selected, then
     * clear the selection and go back to the first page.
     *
     * BulkRecordWriter does the actual work: it is the same path the module
     * listings use for their own bulk actions, so a follow-up child's visits
     * are cleared on a force delete here exactly as they are there, and the
     * operation lands in the activity log as one summary entry per module.
     *
     * @param  callable(EloquentBuilder): int  $write
     */
    protected function applyToSelection(callable $write): bool
    {
        $queries = $this->selectedQueries();

        if ($queries === []) {
            return false;
        }

        $affected = 0;

        foreach ($queries as $query) {
            $affected += $write($query);
        }

        $this->deselectAll();
        $this->resetPage();

        return $affected > 0;
    }

    /**
     * One trashed-records query per module the selection touches.
     *
     * @return array<int, EloquentBuilder>
     */
    protected function selectedQueries(): array
    {
        $queries = [];

        if ($this->selectingAll) {
            foreach (static::modules() as $config) {
                /** @var class-string<Model> $model */
                $model = $config['model'];
                $queries[] = $model::onlyTrashed();
            }

            return $queries;
        }

        $idsByType = [];

        foreach ($this->selected as $key) {
            [$type, $id] = array_pad(explode(':', $key, 2), 2, null);

            if ($type === null || $id === null || ! ctype_digit($id) || ! isset(static::modules()[$type])) {
                continue;
            }

            $idsByType[$type][] = (int) $id;
        }

        foreach ($idsByType as $type => $ids) {
            /** @var class-string<Model> $model */
            $model = static::modules()[$type]['model'];
            $queries[] = $model::onlyTrashed()->whereIn('id', $ids);
        }

        return $queries;
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
