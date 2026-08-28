<?php

namespace App\Exports;

class ChildrenExport extends AbstractTableExport
{
    public function fields(): array
    {
        return [
            'visit_type', 'child_id', 'name', 'phone_number', 'is_pwd', 'organization',
            'implementing_partner', 'date_of_reporting', 'is_displaced', 'screener_profession',
            'sex', 'date_of_birth', 'age_months', 'muac_mm', 'fi', 'has_oedema', 'weight_kg',
            'height_cm', 'whz', 'governorate', 'municipality', 'neighbourhood', 'location',
            'type_of_site', 'is_enrolled_bsfp', 'is_sick_last_6_months', 'is_mother_alive',
            'mother_full_name', 'mother_id_number', 'mother_date_of_birth', 'mother_age_years',
            'mother_phone', 'mother_marital_status', 'mother_muac_mm', 'is_mother_malnourished',
            'father_full_name', 'father_id_number', 'father_phone', 'has_lactating_woman',
            'has_pregnant_last_trimester', 'children_under_5', 'head_of_household_sex',
            'has_stable_income', 'income_source', 'is_income_below_500', 'male_children_under_5',
            'female_children_under_5', 'family_size', 'current_address', 'original_address',
            'has_family_disability', 'disability_cause', 'disability_cause_other',
            'has_injured_after_oct7', 'injured_count', 'has_unaccompanied_children',
            'unaccompanied_children_count', 'has_released_children',
        ];
    }

    public function booleanFields(): array
    {
        return [
            'is_pwd', 'is_displaced', 'has_oedema', 'is_enrolled_bsfp',
            'is_sick_last_6_months', 'is_mother_alive', 'is_mother_malnourished',
            'has_lactating_woman', 'has_pregnant_last_trimester', 'has_stable_income',
            'is_income_below_500', 'has_family_disability', 'has_injured_after_oct7',
            'has_unaccompanied_children', 'has_released_children',
        ];
    }

    public function enumFields(): array
    {
        return [
            'visit_type', 'sex', 'type_of_site', 'head_of_household_sex',
            'income_source', 'disability_cause',
        ];
    }
}
