<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user created, updated or deleted a record in one of the data modules.
 *
 * Fired after the operation has already succeeded, so nothing here can affect
 * or roll back the original save.
 */
class RecordActionOccurred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Model $record,
        public readonly string $action,
        public readonly ?User $actor,
    ) {
    }
}
