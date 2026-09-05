<?php

namespace App\Filament\Pages;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use Carbon\Carbon;
use Filament\Pages\Page;
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
