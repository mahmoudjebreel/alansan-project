<?php

namespace App\Traits;

use App\Events\RecordActionOccurred;
use App\Support\Notifications\ActionType;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Emits a RecordActionOccurred event whenever a record is created, updated or
 * deleted, so Super Admins can be notified.
 *
 * All three hooks fire on the "past tense" model events, i.e. after the write
 * has already happened, so nothing here can alter or roll back the original
 * operation.
 */
trait NotifiesSuperAdminOnChange
{
    public static function bootNotifiesSuperAdminOnChange(): void
    {
        static::created(function ($model): void {
            static::emitRecordActionNotification($model, ActionType::CREATE);
        });

        static::updated(function ($model): void {
            // restore() saves the model, which fires "updated" as well. That
            // is a restore, not an edit, so reporting it as an edit would be
            // wrong — and restore is not one of the actions in scope.
            if (static::notificationSubjectIsBeingRestored($model)) {
                return;
            }

            static::emitRecordActionNotification($model, ActionType::UPDATE);
        });

        static::deleted(function ($model): void {
            static::emitRecordActionNotification(
                $model,
                static::notificationSubjectWasForceDeleted($model) ? ActionType::FORCE_DELETE : ActionType::DELETE,
            );
        });
    }

    /**
     * True when this "updated" event is the save performed by restore().
     */
    protected static function notificationSubjectIsBeingRestored($model): bool
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return false;
        }

        $column = $model->getDeletedAtColumn();

        return $model->wasChanged($column)
            && $model->getAttribute($column) === null;
    }

    /**
     * A hard delete on a soft-deleting model, or any delete on a model that
     * does not soft delete at all.
     */
    protected static function notificationSubjectWasForceDeleted($model): bool
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return true;
        }

        return (bool) $model->isForceDeleting();
    }

    protected static function emitRecordActionNotification($model, string $action): void
    {
        RecordActionOccurred::dispatch($model, $action, Auth::user());
    }
}
