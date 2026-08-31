<?php

namespace App\Policies;

use App\Models\PregnantLactatingWoman;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Every ability Filament can ask about is answered here on purpose.
 *
 * Filament reads an ability it cannot resolve - no policy for the model, or a
 * policy without that exact method - as *allowed*, not denied (see
 * Filament\get_authorization_response). An ability left undeclared is
 * therefore an open door: that is how bulk delete stayed reachable for users
 * without pregnant.delete.
 */
class PregnantLactatingWomanPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('pregnant.view');
    }

    public function view(User $user, PregnantLactatingWoman $record): bool
    {
        return $user->can('pregnant.view');
    }

    public function create(User $user): bool
    {
        return $user->can('pregnant.create');
    }

    public function update(User $user, PregnantLactatingWoman $record): bool
    {
        return $user->can('pregnant.edit');
    }

    public function delete(User $user, PregnantLactatingWoman $record): bool
    {
        return $user->can('pregnant.delete');
    }

    /**
     * What the bulk Delete action authorises against: Filament checks this
     * once for the whole selection rather than once per row.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('pregnant.delete');
    }

    public function restore(User $user, PregnantLactatingWoman $record): bool
    {
        return $user->can('pregnant.delete');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('pregnant.delete');
    }

    public function forceDelete(User $user, PregnantLactatingWoman $record): bool
    {
        return $user->can('pregnant.delete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('pregnant.delete');
    }

    /**
     * Replicating a row writes a new one, so it is a create.
     */
    public function replicate(User $user, PregnantLactatingWoman $record): bool
    {
        return $user->can('pregnant.create');
    }

    public function reorder(User $user): bool
    {
        return $user->can('pregnant.edit');
    }
}
