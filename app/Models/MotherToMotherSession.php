<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\NotifiesSuperAdminOnChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MotherToMotherSession extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, NotifiesSuperAdminOnChange;

    protected $table = 'mother_to_mother_sessions';

    protected $fillable = [
        'session_date',
        'session_group_number',
        'session_subject',
        'session_subject_other',
        'locality',
        'shelter_name',
        'id_number',
        'full_name_ar',
        'visit_type',
        'category',
        'newborn_dob',
        'is_pwd',
        'marital_status',
        'phone_number',
        'receives_supplementary',
    ];

    protected $casts = [
        'session_date' => 'date',
        'newborn_dob' => 'date',
        'is_pwd' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Mother to Mother session record {$eventName}");
    }
}
