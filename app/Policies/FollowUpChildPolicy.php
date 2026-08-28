<?php

namespace App\Policies;

use App\Models\FollowUpChild;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Every ability Filament can ask about is answered here on purpose.
 *
 * Filament reads an ability it cannot resolve - no policy for the model, or a
 * policy without that exact method - as *allowed*, not denied (see
 * Filament\get_authorization_response). An ability left undeclared is
 * therefore an open door: that is how bulk delete stayed reachable for users
 * without follow_up_children.delete.
 */
class FollowUpChildPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('follow_up_children.view');
    }

    public function view(User $user, FollowUpChild $record): bool
    {
        return $user->can('follow_up_children.view');
    }

    public function create(User $user): bool
    {
        return $user->can('follow_up_children.create');
    }

    public function update(User $user, FollowUpChild $record): bool
    {
        return $user->can('follow_up_children.edit');
    }

    public function delete(User $user, FollowUpChild $record): bool
    {
        return $user->can('follow_up_children.delete');
    }

    /**
     * What the bulk Delete action authorises against: Filament checks this
     * once for the whole selection rather than once per row.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('follow_up_children.delete');
    }

    public function restore(User $user, FollowUpChild $record): bool
    {
        return $user->can('follow_up_children.delete');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('follow_up_children.delete');
    }

    public function forceDelete(User $user, FollowUpChild $record): bool
    {
        return $user->can('follow_up_children.delete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('follow_up_children.delete');
    }

    /**
     * Replicating a row writes a new one, so it is a create.
     */
    public function replicate(User $user, FollowUpChild $record): bool
    {
        return $user->can('follow_up_children.create');
    }

    public function reorder(User $user): bool
    {
        return $user->can('follow_up_children.edit');
    }
}
