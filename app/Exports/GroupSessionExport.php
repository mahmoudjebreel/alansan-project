<?php

namespace App\Exports;

class GroupSessionExport extends AbstractTableExport
{
    public function fields(): array
    {
        return ['session_date', 'session_group_number', 'session_subject', 'session_subject_other', 'locality', 'shelter_name', 'id_number', 'full_name_ar', 'visit_type', 'category', 'newborn_dob', 'is_pwd', 'marital_status', 'phone_number', 'has_gsfsh', 'receives_supplementary'];
    }

    public function booleanFields(): array
    {
        return ['is_pwd', 'has_gsfsh'];
    }

    public function enumFields(): array
    {
        return ['session_subject', 'locality', 'shelter_name', 'visit_type', 'category', 'marital_status'];
    }
}
