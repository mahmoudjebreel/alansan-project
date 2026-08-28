<?php

namespace App\Observers;

use App\Events\RecordActionOccurred;
use App\Support\Notifications\ActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Audit notifications for the models that are not one of the six data modules
 * — User, Role and FollowUpChildVisit.
 *
 * The six modules carry App\Traits\NotifiesSuperAdminOnChange instead, which
 * is where create/update/delete/force-delete is handled. This observer stays
 * for the remaining models so the coverage that existed before the
 * notification system was built is not silently lost. It now feeds the same
 * event pipeline rather than writing notifications itself.
 */
class AuditNotificationObserver
{
    public function created(Model $model): void
    {
        $this->emit($model, ActionType::CREATE);
    }

    public function deleted(Model $model): void
    {
        $forceDeleting = method_exists($model, 'isForceDeleting')
            ? (bool) $model->isForceDeleting()
            : true;

        $this->emit($model, $forceDeleting ? ActionType::FORCE_DELETE : ActionType::DELETE);
    }

    private function emit(Model $model, string $action): void
    {
        RecordActionOccurred::dispatch($model, $action, Auth::user());
    }
}
