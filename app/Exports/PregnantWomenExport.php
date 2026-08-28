<?php

namespace App\Exports;

class PregnantWomenExport extends AbstractTableExport
{
    public function fields(): array
    {
        return [
            'visit_type', 'mother_id', 'full_name_ar', 'phone_number', 'is_pwd', 'organization',
            'implementing_partner', 'date_of_reporting', 'is_displaced', 'screener_profession',
            'date_of_birth', 'age_years', 'status_type', 'weight_kg', 'height_cm', 'muac_mm',
            'fi', 'has_oedema', 'governorate', 'municipality', 'neighbourhood', 'location',
            'type_of_site', 'disability_type', 'newborn_dob', 'status', 'husband_id_number',
            'husband_full_name', 'husband_phone', 'family_size', 'children_count', 'is_family_pwd',
        ];
    }

    public function booleanFields(): array
    {
        return ['is_pwd', 'is_displaced', 'has_oedema', 'is_family_pwd'];
    }

    public function enumFields(): array
    {
        return ['visit_type', 'status_type'];
    }
}
