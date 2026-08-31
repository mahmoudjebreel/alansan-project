<?php

namespace App\Exports;

class MotherToMotherExport extends AbstractTableExport
{
    public function fields(): array
    {
        return [
            'session_date', 'session_group_number', 'session_subject', 'session_subject_other',
            'locality', 'shelter_name', 'id_number', 'full_name_ar', 'visit_type',
            'category', 'newborn_dob', 'is_pwd', 'marital_status', 'phone_number',
            'receives_supplementary',
        ];
    }

    public function booleanFields(): array
    {
        return ['is_pwd'];
    }

    public function enumFields(): array
    {
        return ['session_subject', 'locality', 'visit_type', 'category', 'marital_status'];
    }
}
