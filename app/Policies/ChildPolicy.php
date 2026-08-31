<?php

namespace App\Policies;

use App\Models\Child;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Every ability Filament can ask about is answered here on purpose.
 *
 * Filament reads an ability it cannot resolve - no policy for the model, or a
 * policy without that exact method - as *allowed*, not denied (see
 * Filament\get_authorization_response). An ability left undeclared is
 * therefore an open door: that is how bulk delete stayed reachable for users
 * without children.delete.
 */
class ChildPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('children.view');
    }

    public function view(User $user, Child $record): bool
    {
        return $user->can('children.view');
    }

    public function create(User $user): bool
    {
        return $user->can('children.create');
    }

    public function update(User $user, Child $record): bool
    {
        return $user->can('children.edit');
    }

    public function delete(User $user, Child $record): bool
    {
        return $user->can('children.delete');
    }

    /**
     * What the bulk Delete action authorises against: Filament checks this
     * once for the whole selection rather than once per row.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('children.delete');
    }

    public function restore(User $user, Child $record): bool
    {
        return $user->can('children.delete');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('children.delete');
    }

    public function forceDelete(User $user, Child $record): bool
    {
        return $user->can('children.delete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('children.delete');
    }

    /**
     * Replicating a row writes a new one, so it is a create.
     */
    public function replicate(User $user, Child $record): bool
    {
        return $user->can('children.create');
    }

    public function reorder(User $user): bool
    {
        return $user->can('children.edit');
    }
}
