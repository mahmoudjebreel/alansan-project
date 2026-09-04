<?php

namespace App\Support;

use App\Support\Notifications\ActionNotifier;
use App\Support\Notifications\ActionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Set-based delete / restore for the data modules.
 *
 * Row-by-row Eloquent deletes are what made "delete 5,000 records" unusable:
 * every model fired LogsActivity (one activity_log INSERT carrying the whole
 * row as JSON) and NotifiesSuperAdminOnChange (one queued notification job),
 * so a single click turned into roughly three writes per record on top of the
 * delete itself.
 *
 * Everything here works on primary keys and issues one statement per chunk, so
 * the cost is proportional to the number of chunks rather than the number of
 * records. The per-record side effects are suppressed for the duration and
 * replaced by a single summary of the whole operation - which is also what a
 * reader of the audit log actually wants to see for a bulk action.
 */
final class BulkRecordWriter
{
    /**
     * Rows touched per statement. Large enough that 100k records stay a few
     * hundred statements, small enough that the IN (...) list and the row locks
     * it takes never grow past what MySQL handles comfortably.
     */
    public const CHUNK_SIZE = 1000;

    /**
     * Soft delete every record matched by the query.
     */
    public static function softDelete(Builder $query): int
    {
        $model = $query->getModel();

        if (! static::softDeletes($model)) {
            return static::forceDelete($query);
        }

        return static::run($query, ActionType::DELETE, function (array $keys) use ($model): int {
            static::cascade($model, $keys, forceDeleting: false);

            return $model->newQueryWithoutScopes()
                ->whereIn($model->getKeyName(), $keys)
                ->whereNull($model->getDeletedAtColumn())
                ->update([
                    $model->getDeletedAtColumn() => now(),
                    $model->getUpdatedAtColumn() => now(),
                ]);
        });
    }

    /**
     * Permanently delete every record matched by the query.
     */
    public static function forceDelete(Builder $query): int
    {
        $model = $query->getModel();

        return static::run($query, ActionType::FORCE_DELETE, function (array $keys) use ($model): int {
            static::cascade($model, $keys, forceDeleting: true);

            return $model->newQueryWithoutScopes()
                ->whereIn($model->getKeyName(), $keys)
                ->delete();
        });
    }

    /**
     * Restore every trashed record matched by the query.
     *
     * Restoring is not one of the audited actions, so it produces no summary
     * notification - only the batched activity entry that run() writes.
     */
    public static function restore(Builder $query): int
    {
        $model = $query->getModel();

        if (! static::softDeletes($model)) {
            return 0;
        }

        return static::run($query, action: null, callback: fn (array $keys): int => $model->newQueryWithoutScopes()
            ->whereIn($model->getKeyName(), $keys)
            ->whereNotNull($model->getDeletedAtColumn())
            ->update([
                $model->getDeletedAtColumn() => null,
                $model->getUpdatedAtColumn() => now(),
            ]));
    }

    /**
     * Walk the matched keys in chunks, applying $callback to each chunk with
     * every per-record side effect switched off, then record one summary.
     *
     * The whole run is a single transaction: a bulk delete that half succeeded
     * would leave the operator with no way to tell which half.
     */
    private static function run(Builder $query, ?string $action, callable $callback): int
    {
        $model = $query->getModel();
        $keyName = $model->getKeyName();

        $keys = $query->reorder()->pluck($model->qualifyColumn($keyName));

        if ($keys->isEmpty()) {
            return 0;
        }

        $affected = 0;

        // withoutEvents() covers LogsActivity and NotifiesSuperAdminOnChange in
        // one place; the notifier guard is belt-and-braces for anything that
        // reaches ActionNotifier without going through a model event.
        ActionNotifier::withoutRecordNotifications(function () use ($model, $keys, $callback, &$affected): void {
            $affected = $model::withoutEvents(fn (): int => DB::transaction(
                fn (): int => $keys->chunk(self::CHUNK_SIZE)
                    ->sum(fn (Collection $chunk): int => (int) $callback($chunk->all())),
            ));
        });

        if ($affected > 0) {
            static::recordSummary($model, $action, $affected, $keys->take(self::CHUNK_SIZE)->all());
        }

        return $affected;
    }

    /**
     * Delete the rows that the model's own "deleting" hook would have removed
     * for each record, in one statement per relation instead of one per record.
     *
     * Only the relations a model lists in bulkCascades() are touched; a model
     * that lists none needs nothing here.
     */
    private static function cascade(Model $model, array $keys, bool $forceDeleting): void
    {
        if (! method_exists($model, 'bulkCascades')) {
            return;
        }

        foreach ($model->bulkCascades($forceDeleting) as $relation => $foreignKey) {
            $related = $model->{$relation}()->getRelated();

            $related->newQueryWithoutScopes()
                ->whereIn($foreignKey, $keys)
                ->delete();
        }
    }

    /**
     * One activity entry and one notification for the whole operation.
     *
     * The entry names the module and the count rather than a single subject,
     * because there is no single subject: pinning it on the first record read
     * as "this one row was deleted" and hid the other 4,999. The sample of IDs
     * is what lets an operator find the affected rows afterwards.
     */
    private static function recordSummary(Model $model, ?string $action, int $count, array $sampleKeys): void
    {
        $module = class_basename($model);
        $verb = $action ?? 'restore';

        activity()
            ->useLog('bulk')
            ->causedBy(Auth::user())
            ->withProperties([
                'module' => $module,
                'action' => $verb,
                'count' => $count,
                'sample_ids' => array_slice($sampleKeys, 0, 20),
            ])
            ->log("{$module} bulk {$verb} ({$count})");

        if ($action !== null) {
            ActionNotifier::bulk($model, $action, Auth::user(), $count);
        }
    }

    private static function softDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
