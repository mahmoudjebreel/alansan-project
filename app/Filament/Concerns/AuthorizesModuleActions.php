<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Answers every authorization question Filament can ask a resource, from one
 * module permission prefix (`children`, `mother_to_mother`, ...).
 *
 * Why this exists rather than the policy alone: Filament treats an ability it
 * cannot resolve - no policy for the model, or a policy without that exact
 * method - as *allowed*, not denied (see Filament\get_authorization_response).
 * Mother to Mother had no discoverable policy, so `deleteAny` fell through to
 * that fallback and the bulk Delete button worked for everyone.
 *
 * The policies are still the backend guard; this trait makes sure a resource
 * never silently depends on one being found. Both layers now answer the same
 * question the same way.
 */
trait AuthorizesModuleActions
{
    /**
     * Permission prefix for this module, e.g. `children` for `children.edit`.
     */
    abstract public static function permissionPrefix(): string;

    /**
     * can() rather than hasPermissionTo(): it honours the Gate::before rule
     * that grants Super Admin everything without explicit assignments.
     */
    public static function allowsAction(string $action): bool
    {
        return auth()->user()?->can(static::permissionPrefix() . '.' . $action) ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::allowsAction('view');
    }

    public static function canView(Model $record): bool
    {
        return static::allowsAction('view');
    }

    public static function canCreate(): bool
    {
        return static::allowsAction('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowsAction('edit');
    }

    public static function canDelete(Model $record): bool
    {
        return static::allowsAction('delete');
    }

    /**
     * What the bulk Delete action authorises against. Filament checks this
     * once for the whole selection rather than per row.
     */
    public static function canDeleteAny(): bool
    {
        return static::allowsAction('delete');
    }

    public static function canRestore(Model $record): bool
    {
        return static::allowsAction('delete');
    }

    public static function canRestoreAny(): bool
    {
        return static::allowsAction('delete');
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::allowsAction('delete');
    }

    public static function canForceDeleteAny(): bool
    {
        return static::allowsAction('delete');
    }

    /**
     * Replicating a row writes a new one, so it is a create.
     */
    public static function canReplicate(Model $record): bool
    {
        return static::allowsAction('create');
    }

    public static function canReorder(): bool
    {
        return static::allowsAction('edit');
    }

    public static function canExport(): bool
    {
        return static::allowsAction('export');
    }

    public static function canImport(): bool
    {
        return static::allowsAction('import');
    }
}
