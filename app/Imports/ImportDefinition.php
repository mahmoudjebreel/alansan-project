<?php

namespace App\Imports;

use App\Exports\AbstractTableExport;
use App\Exports\ChildrenExport;
use App\Exports\FollowUpChildrenExport;
use App\Exports\GroupSessionExport;
use App\Exports\IndividualCounselingExport;
use App\Exports\MotherToMotherExport;
use App\Exports\PregnantWomenExport;
use App\Models\Child;
use App\Support\Import\ChildImportDates;
use App\Support\Import\ImportedRowDeriver;
use App\Support\Import\PregnantWomanImportDates;
use App\Support\Import\PregnantWomanImportSynonyms;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use Illuminate\Database\Eloquent\Model;

/**
 * Central registry describing how each module is imported.
 *
 * The column structure always comes from the module's Export class, so the
 * downloadable template and the Excel export can never drift apart.
 */
final class ImportDefinition
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string<AbstractTableExport>  $export
     * @param  class-string  $resource  Filament resource, used to read the
     *                                  Select options and required fields that
     *                                  the manual Create form already enforces.
     * @param  array<string>  $computed  Fields the system derives itself. They are
     *                                   stripped from uploaded rows so a value in
     *                                   the file can never override the calculation.
     * @param  array<string, array<string, string>>  $synonyms  Spellings a real file
     *                                   uses that the Select does not offer, as
     *                                   field => [accepted input => stored value].
     *                                   Matched after the option list itself, so a
     *                                   synonym can never shadow a real option.
     * @param  array<string, array<string>>  $headingAliases  Column headings a real
     *                                   file carries that the export does not write,
     *                                   as field => [accepted heading, ...]. The
     *                                   teams fill in a form of their own whose
     *                                   columns are named "Date of session" and
     *                                   "Sesion subject", and a heading the importer
     *                                   cannot place is not merely unread - the
     *                                   column is dropped in silence, and the upload
     *                                   fails on the one dropped column the database
     *                                   will not do without. Matched after the
     *                                   canonical headings, so an alias can never
     *                                   shadow a real column name.
     * @param  array<string>  $collapseWhitespace  Free-text fields whose inner runs
     *                                   of spaces are squeezed to one before the
     *                                   value is stored, so "mixed  feeding" and
     *                                   "mixed feeding" do not become two spellings.
     * @param  class-string|null  $dateReader  Module-specific reader for date cells
     *                                   that arrive as typed text rather than as a
     *                                   real Excel date. Must expose
     *                                   normalise(string $field, mixed $value): mixed,
     *                                   returning an unambiguous Y-m-d string, null to
     *                                   drop an unreadable optional cell, or the value
     *                                   untouched to let the ordinary date rule refuse
     *                                   it. Without one, a hand-typed cell goes to
     *                                   Carbon::parse() and is read month-first.
     * @param  callable|null  $deriver  Recomputes the columns the system decides
     *                                   for itself - visit type, age - from the
     *                                   row's own values and the records already
     *                                   stored. Takes and returns the attribute
     *                                   array. Without one, a bulk upload is the
     *                                   one door through which a hand-typed
     *                                   visit type reaches the database.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $model,
        public readonly string $export,
        public readonly string $resource,
        public readonly string $permission,
        public readonly string $filename,
        public readonly array $computed = [],
        public readonly array $synonyms = [],
        public readonly array $headingAliases = [],
        public readonly array $collapseWhitespace = [],
        public readonly ?string $dateReader = null,
        public readonly mixed $deriver = null,
    ) {
    }

    /**
     * Apply this module's deriver to one uploaded row.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function derive(array $attributes): array
    {
        if (! is_callable($this->deriver)) {
            return $attributes;
        }

        return ($this->deriver)($attributes);
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        static $definitions = null;

        return $definitions ??= collect([
            new self(
                key: 'children',
                label: __('fields.children'),
                model: Child::class,
                export: ChildrenExport::class,
                resource: \App\Filament\Resources\ChildResource::class,
                permission: 'children.import',
                filename: 'children',
                // FI is always re-derived from MUAC by the model.
                computed: ['fi'],
                // Every Select column of this module, in both languages.
                //
                // ImportSchema already accepts the stored value, the form's
                // label and the Arabic translation of the stored value on its
                // own; those spellings are repeated here anyway because this
                // map is also what a rejection message lists back to the
                // uploader, and a message naming only the English labels told
                // people their perfectly valid Arabic sheet was wrong.
                //
                // Matching is literal - trimmed, whitespace squeezed,
                // lowercased, Arabic diacritics and alef/ya/ta-marbuta forms
                // unified - and nothing else. No fuzzy matching, so a real
                // misspelling is refused rather than read as whatever it looks
                // closest to.
                //
                // Boolean columns are deliberately absent: castBoolean() runs
                // before the option matching and already reads نعم/لا, Yes/No,
                // Y/N, true/false, 1/0 and a real Excel boolean cell, so
                // entries here would never be consulted.
                // @see \App\Support\ImportSchema::castValue()
                synonyms: [
                    'visit_type' => [
                        'جديد' => 'new',
                        'جديدة' => 'new',
                        'New' => 'new',
                        'N' => 'new',

                        'متابعة' => 'follow_up',
                        'Follow-up' => 'follow_up',
                        'Follow up' => 'follow_up',
                        'Followup' => 'follow_up',
                        'Follow' => 'follow_up',
                        'F/U' => 'follow_up',
                        'FU' => 'follow_up',
                        // The doubled suffix a real workbook was found
                        // carrying, and which cost a whole upload.
                        'Follow-up-up' => 'follow_up',
                    ],

                    'sex' => [
                        'ذكر' => 'male',
                        'Male' => 'male',
                        'M' => 'male',

                        'أنثى' => 'female',
                        'Female' => 'female',
                        'F' => 'female',
                    ],

                    'head_of_household_sex' => [
                        'ذكر' => 'male',
                        'Male' => 'male',
                        'M' => 'male',

                        'أنثى' => 'female',
                        'Female' => 'female',
                        'F' => 'female',
                    ],

                    // The four sites, spelled as each team writes them.
                    'type_of_site' => [
                        'مخيم السلام' => 'El Salam Camp',
                        'السلام' => 'El Salam Camp',
                        'El Salam Camp' => 'El Salam Camp',
                        'Al Salam Camp' => 'El Salam Camp',
                        'El Salam' => 'El Salam Camp',

                        'مخيم مصعب' => 'Mossab Camp',
                        'مصعب' => 'Mossab Camp',
                        'Mossab Camp' => 'Mossab Camp',
                        'Mosaab Camp' => 'Mossab Camp',
                        'Musab Camp' => 'Mossab Camp',

                        'مخيم المحبة' => 'Mahabba Camp',
                        'المحبة' => 'Mahabba Camp',
                        'محبة' => 'Mahabba Camp',
                        'Mahabba' => 'Mahabba Camp',
                        'Mahabba Camp' => 'Mahabba Camp',

                        'مخيم القوقا' => 'El Qoqa',
                        'القوقا' => 'El Qoqa',
                        'El Qoqa' => 'El Qoqa',
                        'Al Qoqa' => 'El Qoqa',
                    ],

                    // Stored in Arabic, which is what the form offers.
                    'mother_marital_status' => [
                        'متزوجة' => 'متزوجة',
                        'Married' => 'متزوجة',

                        'مطلقة' => 'مطلقة',
                        'Divorced' => 'مطلقة',

                        'أرملة' => 'أرملة',
                        'ارملة' => 'أرملة',
                        'Widow' => 'أرملة',
                        'Widowed' => 'أرملة',

                        'منفصلة' => 'منفصلة',
                        'Separated' => 'منفصلة',
                    ],

                    // Written out in every spelling the files actually use.
                    // Normalising unifies the alef and ya forms and squeezes
                    // whitespace, but it does not remove brackets or the
                    // definite article - so "وكالة (أونروا)" matching told us
                    // nothing about "وكالة أونروا", which is what the sheets
                    // are mostly written in and which was being refused.
                    'income_source' => [
                        'حكومي' => 'government',
                        'حكومية' => 'government',
                        'حكومة' => 'government',
                        'الحكومة' => 'government',
                        'Government' => 'government',
                        'Governmental' => 'government',
                        'Govt' => 'government',

                        'وكالة (أونروا)' => 'unrwa',
                        'وكالة أونروا' => 'unrwa',
                        'وكالة الغوث' => 'unrwa',
                        'وكالة' => 'unrwa',
                        'الوكالة' => 'unrwa',
                        'أونروا' => 'unrwa',
                        'الأونروا' => 'unrwa',
                        'UNRWA' => 'unrwa',
                        'UNRWA Agency' => 'unrwa',
                        'Agency' => 'unrwa',

                        'أخرى' => 'other',
                        'غير ذلك' => 'other',
                        'Other' => 'other',
                        'Others' => 'other',
                    ],

                    'disability_cause' => [
                        'الحرب' => 'war',
                        'حرب' => 'war',
                        'إصابة حرب' => 'war',
                        'War' => 'war',
                        'War Injury' => 'war',

                        'أخرى' => 'other',
                        'غير ذلك' => 'other',
                        'Other' => 'other',
                        'Others' => 'other',
                    ],
                ],
                // These workbooks carry hand-typed dates too, and Carbon reads
                // a slashed one month-first: "31/12/1990" was refused outright
                // and "7/12/95" imported as the 12th of July without a word.
                dateReader: ChildImportDates::class,
                // Visit type and age are decided by the system on the form, so
                // an uploaded file must not be able to state them either.
                deriver: [ImportedRowDeriver::class, 'children'],
            ),
            new self(
                key: 'pregnant',
                label: __('fields.pregnant_lactating_women'),
                model: PregnantLactatingWoman::class,
                export: PregnantWomenExport::class,
                resource: \App\Filament\Resources\PregnantLactatingWomanResource::class,
                permission: 'pregnant.import',
                filename: 'pregnant-lactating-women',
                computed: ['fi'],
                // The workbooks for this module arrive in Arabic from one team
                // and in English from the next; both spellings of every Select
                // column have to land on the same stored value.
                synonyms: PregnantWomanImportSynonyms::forImportDefinition(),
                // The same workbooks carry hand-typed dates, which Carbon reads
                // month-first: "7/12/95" imported as the 12th of July and said
                // nothing about it.
                dateReader: PregnantWomanImportDates::class,
                deriver: [ImportedRowDeriver::class, 'pregnant'],
            ),
            new self(
                key: 'group_sessions',
                label: __('fields.group_sessions'),
                model: GroupSession::class,
                export: GroupSessionExport::class,
                resource: \App\Filament\Resources\GroupSessionResource::class,
                permission: 'group_sessions.import',
                filename: 'group-sessions',
                // Both languages for every Select column; see the children
                // block above for why the full labels are repeated here.
                synonyms: [
                    'visit_type' => [
                        'جديد' => 'new',
                        'جديدة' => 'new',
                        'New' => 'new',
                        'N' => 'new',

                        'متابعة' => 'follow_up',
                        'Follow-up' => 'follow_up',
                        'Follow up' => 'follow_up',
                        'Followup' => 'follow_up',
                        'Follow' => 'follow_up',
                        'F/U' => 'follow_up',
                        'FU' => 'follow_up',
                        'Follow-up-up' => 'follow_up',
                    ],

                    'session_subject' => [
                        'دعم الرضاعة الطبيعية' => 'bf_support',
                        'دعم الرضاعة' => 'bf_support',
                        'BF Support' => 'bf_support',
                        'Breastfeeding Support' => 'bf_support',
                        'Breast Feeding Support' => 'bf_support',
                        'BF' => 'bf_support',

                        'إعادة الإرضاع' => 'relactation',
                        'اعادة الرضاعة' => 'relactation',
                        'Relactation' => 'relactation',
                        'Re-lactation' => 'relactation',

                        // The form spells it "Complimentary"; a sheet written
                        // by anyone who knows the term spells it correctly.
                        'التغذية التكميلية' => 'complimentary_feeding',
                        'تغذية تكميلية' => 'complimentary_feeding',
                        'الغذاء التكميلي' => 'complimentary_feeding',
                        'Complimentary Feeding' => 'complimentary_feeding',
                        'Complementary Feeding' => 'complimentary_feeding',
                        'CF' => 'complimentary_feeding',

                        'أخرى' => 'other',
                        'Other' => 'other',
                    ],

                    'locality' => [
                        'تل الهوى' => 'tal_al_hawa',
                        'تل الهوا' => 'tal_al_hawa',
                        'Tal Al Hawa' => 'tal_al_hawa',
                        'Tal El Hawa' => 'tal_al_hawa',
                        'Tal EalHawa' => 'tal_al_hawa',

                        'الصفطاوي' => 'el_saftawi',
                        'El Saftawi' => 'el_saftawi',
                        'Al Saftawi' => 'el_saftawi',

                        'النفق' => 'el_nafaq',
                        'El Nafaq' => 'el_nafaq',
                        'Al Nafaq' => 'el_nafaq',

                        'الشاطئ' => 'el_shatee',
                        'الشاطي' => 'el_shatee',
                        'El Shatee' => 'el_shatee',
                        'Al Shatee' => 'el_shatee',

                        'الكرامة' => 'karamah',
                        'كرامة' => 'karamah',
                        'Karamah' => 'karamah',
                        'Karama' => 'karamah',
                        'El Karamah' => 'karamah',
                    ],

                    'shelter_name' => [
                        'مخيم مصعب' => 'mosaab_camp',
                        'مصعب' => 'mosaab_camp',
                        'Mosaab Camp' => 'mosaab_camp',
                        'Mossab Camp' => 'mosaab_camp',
                        'Musab Camp' => 'mosaab_camp',

                        'المحبة' => 'mahabba',
                        'مخيم المحبة' => 'mahabba',
                        'محبة' => 'mahabba',
                        'Mahabba' => 'mahabba',
                        'Mahabba Camp' => 'mahabba',

                        'السلام' => 'el_salam',
                        'مخيم السلام' => 'el_salam',
                        'El Salam' => 'el_salam',
                        'El Salam Camp' => 'el_salam',
                        'Al Salam Camp' => 'el_salam',

                        'القوقا' => 'el_qoqa',
                        'مخيم القوقا' => 'el_qoqa',
                        'El Qoqa' => 'el_qoqa',
                        'Al Qoqa' => 'el_qoqa',

                        // The fifth shelter. Individual Counseling has offered
                        // it all along; Group Sessions only got it once a
                        // camp's worth of sessions was refused for naming it.
                        'الحلو' => 'al_helou',
                        'مخيم الحلو' => 'al_helou',
                        'Al Helou' => 'al_helou',
                        'El Helou' => 'al_helou',
                    ],

                    'category' => [
                        'الجدات' => 'grandmothers',
                        'جدات' => 'grandmothers',
                        'Grandmothers' => 'grandmothers',
                        'Grandmother' => 'grandmothers',

                        'سن الإنجاب' => 'reproductive_age',
                        'نساء سن الإنجاب' => 'reproductive_age',
                        'Reproductive Age' => 'reproductive_age',
                        'Reproductive' => 'reproductive_age',

                        'ذكر' => 'male',
                        'ذكور' => 'male',
                        'Male' => 'male',
                        'Males' => 'male',

                        'مقدم رعاية لطفل أقل من 6 أشهر' => 'caregiver_child_under_6_months',
                        'مقدم رعاية أقل من 6 أشهر' => 'caregiver_child_under_6_months',
                        'Caregiver with Child <6 Months' => 'caregiver_child_under_6_months',
                        'Caregiver <6 Months' => 'caregiver_child_under_6_months',
                        // The teams' own form says "infant" where the Select
                        // says "child".
                        'Caregiver with infant <6 months' => 'caregiver_child_under_6_months',

                        'مقدم رعاية لطفل 6-23 شهراً' => 'caregiver_child_6_23_months',
                        'مقدم رعاية 6-23 شهر' => 'caregiver_child_6_23_months',
                        'Caregiver with Child 6-23 Months' => 'caregiver_child_6_23_months',
                        'Caregiver 6-23 Months' => 'caregiver_child_6_23_months',

                        'حامل' => 'pregnant',
                        'حوامل' => 'pregnant',
                        'Pregnant' => 'pregnant',
                        'Pregnant Women' => 'pregnant',
                        // The column these sheets fill in is headed "P or L or
                        // other", and P is the only one of the three the teams
                        // actually write.
                        'P' => 'pregnant',
                    ],

                    // This module's participants include men, so the masculine
                    // Arabic forms are as ordinary here as the feminine ones.
                    'marital_status' => [
                        'متزوجة' => 'married',
                        'متزوج' => 'married',
                        'Married' => 'married',

                        'مطلقة' => 'divorced',
                        'مطلق' => 'divorced',
                        'Divorced' => 'divorced',

                        'أرملة' => 'widow',
                        'ارملة' => 'widow',
                        'أرمل' => 'widow',
                        'Widow' => 'widow',
                        'Widowed' => 'widow',

                        'منفصلة' => 'separated',
                        'منفصل' => 'separated',
                        'Separated' => 'separated',
                    ],
                ],
                // The teams do not fill in the downloadable template: they
                // fill in a form of their own, whose column names are the ones
                // below - typos, casing and all. Every spelling here was read
                // off a workbook that was actually submitted, and none of them
                // is a guess. Matched after the real headings, so none of them
                // can shadow a column the export itself writes.
                headingAliases: [
                    'session_date' => ['Date of session', 'Date of Session'],
                    'session_group_number' => ['Sesion group number', 'Session group number'],
                    'session_subject' => ['Sesion subject'],
                    'id_number' => ['ID No', 'ID Number'],
                    'full_name_ar' => ['Name in Arabic (4 Names)', 'Name in Arabic'],
                    'visit_type' => ['New/Follow Up'],
                    'category' => ['P or L or other'],
                    'newborn_dob' => ['If L woman, DOB of Newborn', 'DOB of Newborn'],
                    'is_pwd' => ['PwD'],
                    'receives_supplementary' => ['Receive supplementary'],
                ],
                deriver: [ImportedRowDeriver::class, 'groupSessions'],
            ),
            new self(
                key: 'mother_to_mother',
                label: __('fields.mother_to_mother_sessions'),
                model: MotherToMotherSession::class,
                export: MotherToMotherExport::class,
                resource: \App\Filament\Resources\MotherToMotherResource::class,
                permission: 'mother_to_mother.import',
                filename: 'mother-to-mother-sessions',
                // The same columns as the group sessions, with one difference
                // that matters: this module's `locality` holds the camp names,
                // where the group sessions' holds the neighbourhoods. The two
                // maps are therefore not interchangeable and are written out
                // separately rather than shared.
                synonyms: [
                    'visit_type' => [
                        'جديد' => 'new',
                        'جديدة' => 'new',
                        'New' => 'new',
                        'N' => 'new',

                        'متابعة' => 'follow_up',
                        'Follow-up' => 'follow_up',
                        'Follow up' => 'follow_up',
                        'Followup' => 'follow_up',
                        'Follow' => 'follow_up',
                        'F/U' => 'follow_up',
                        'FU' => 'follow_up',
                        'Follow-up-up' => 'follow_up',
                    ],

                    'session_subject' => [
                        'دعم الرضاعة الطبيعية' => 'bf_support',
                        'دعم الرضاعة' => 'bf_support',
                        'BF Support' => 'bf_support',
                        'Breastfeeding Support' => 'bf_support',
                        'Breast Feeding Support' => 'bf_support',
                        'BF' => 'bf_support',

                        'إعادة الإرضاع' => 'relactation',
                        'اعادة الرضاعة' => 'relactation',
                        'Relactation' => 'relactation',
                        'Re-lactation' => 'relactation',

                        'التغذية التكميلية' => 'complimentary_feeding',
                        'تغذية تكميلية' => 'complimentary_feeding',
                        'الغذاء التكميلي' => 'complimentary_feeding',
                        'Complimentary Feeding' => 'complimentary_feeding',
                        'Complementary Feeding' => 'complimentary_feeding',
                        'CF' => 'complimentary_feeding',

                        'أخرى' => 'other',
                        'Other' => 'other',
                    ],

                    'locality' => [
                        'مخيم مصعب' => 'mosaab_camp',
                        'مصعب' => 'mosaab_camp',
                        'Mosaab Camp' => 'mosaab_camp',
                        'Mossab Camp' => 'mosaab_camp',
                        'Musab Camp' => 'mosaab_camp',

                        'مخيم السلام' => 'el_salam_camp',
                        'السلام' => 'el_salam_camp',
                        'El Salam Camp' => 'el_salam_camp',
                        'El Salam' => 'el_salam_camp',
                        'Al Salam Camp' => 'el_salam_camp',

                        'مخيم المحبة' => 'mahabba_camp',
                        'المحبة' => 'mahabba_camp',
                        'محبة' => 'mahabba_camp',
                        'Mahabba Camp' => 'mahabba_camp',
                        'Mahabba' => 'mahabba_camp',

                        'القوقا' => 'el_qoqa',
                        'مخيم القوقا' => 'el_qoqa',
                        'El Qoqa' => 'el_qoqa',
                        'Al Qoqa' => 'el_qoqa',
                    ],

                    'category' => [
                        'الجدات' => 'grandmothers',
                        'جدات' => 'grandmothers',
                        'Grandmothers' => 'grandmothers',
                        'Grandmother' => 'grandmothers',

                        'سن الإنجاب' => 'reproductive_age',
                        'نساء سن الإنجاب' => 'reproductive_age',
                        'Reproductive Age' => 'reproductive_age',
                        'Reproductive' => 'reproductive_age',

                        'ذكر' => 'male',
                        'ذكور' => 'male',
                        'Male' => 'male',
                        'Males' => 'male',

                        'مقدم رعاية لطفل أقل من 6 أشهر' => 'caregiver_child_under_6_months',
                        'مقدم رعاية أقل من 6 أشهر' => 'caregiver_child_under_6_months',
                        'Caregiver with Child <6 Months' => 'caregiver_child_under_6_months',
                        'Caregiver <6 Months' => 'caregiver_child_under_6_months',
                        // The teams' own form says "infant" where the Select
                        // says "child".
                        'Caregiver with infant <6 months' => 'caregiver_child_under_6_months',

                        'مقدم رعاية لطفل 6-23 شهراً' => 'caregiver_child_6_23_months',
                        'مقدم رعاية 6-23 شهر' => 'caregiver_child_6_23_months',
                        'Caregiver with Child 6-23 Months' => 'caregiver_child_6_23_months',
                        'Caregiver 6-23 Months' => 'caregiver_child_6_23_months',

                        'حامل' => 'pregnant',
                        'حوامل' => 'pregnant',
                        'Pregnant' => 'pregnant',
                        'Pregnant Women' => 'pregnant',
                        // The column these sheets fill in is headed "P or L or
                        // other", and P is the only one of the three the teams
                        // actually write.
                        'P' => 'pregnant',
                    ],

                    'marital_status' => [
                        'متزوجة' => 'married',
                        'متزوج' => 'married',
                        'Married' => 'married',

                        'مطلقة' => 'divorced',
                        'مطلق' => 'divorced',
                        'Divorced' => 'divorced',

                        'أرملة' => 'widow',
                        'ارملة' => 'widow',
                        'أرمل' => 'widow',
                        'Widow' => 'widow',
                        'Widowed' => 'widow',

                        'منفصلة' => 'separated',
                        'منفصل' => 'separated',
                        'Separated' => 'separated',
                    ],
                ],
                // The teams do not fill in the downloadable template: they
                // fill in a form of their own, whose column names are the ones
                // below - typos, casing and all. Every spelling here was read
                // off a workbook that was actually submitted, and none of them
                // is a guess. Matched after the real headings, so none of them
                // can shadow a column the export itself writes.
                headingAliases: [
                    'session_date' => ['Date of session', 'Date of Session'],
                    'session_group_number' => ['Sesion group number', 'Session group number'],
                    'session_subject' => ['Sesion subject'],
                    'id_number' => ['ID No', 'ID Number'],
                    'full_name_ar' => ['Name in Arabic (4 Names)', 'Name in Arabic'],
                    'visit_type' => ['New/Follow Up'],
                    'category' => ['P or L or other'],
                    'newborn_dob' => ['If L woman, DOB of Newborn', 'DOB of Newborn'],
                    'is_pwd' => ['PwD'],
                    'receives_supplementary' => ['Receive supplementary'],
                ],
            ),
            new self(
                key: 'individual_counseling',
                label: __('fields.individual_counselings'),
                model: IndividualCounseling::class,
                export: IndividualCounselingExport::class,
                resource: \App\Filament\Resources\IndividualCounselingResource::class,
                permission: 'individual_counseling.import',
                filename: 'individual-counselings',
                // MUAC degree is always re-derived from MUAC by the model.
                computed: ['muac_degree'],
                // Spellings the programme's own workbooks are full of.
                synonyms: [
                    'child_visit_type' => [
                        'جديد' => 'new',
                        'جديدة' => 'new',
                        'New' => 'new',
                        'N' => 'new',

                        'متابعة' => 'follow_up',
                        'follow' => 'follow_up',
                        'f/u' => 'follow_up',
                        'FU' => 'follow_up',
                        'Follow-up' => 'follow_up',
                        'Follow up' => 'follow_up',
                        'Followup' => 'follow_up',
                        'Follow-up-up' => 'follow_up',
                    ],
                    'mother_visit_type' => [
                        'جديد' => 'new',
                        'جديدة' => 'new',
                        'New' => 'new',
                        'N' => 'new',

                        'متابعة' => 'follow_up',
                        'follow' => 'follow_up',
                        'f/u' => 'follow_up',
                        'FU' => 'follow_up',
                        'Follow-up' => 'follow_up',
                        'Follow up' => 'follow_up',
                        'Followup' => 'follow_up',
                        'Follow-up-up' => 'follow_up',
                    ],
                    // The composite is stored as P+L and shown as P/L. The
                    // labels read "P (حامل)", so a cell holding the bare word
                    // matches nothing without these.
                    'p_l' => [
                        'p/l' => 'P+L',
                        'pl' => 'P+L',
                        'P+L' => 'P+L',
                        'حامل ومرضع' => 'P+L',
                        'حامل + مرضع' => 'P+L',
                        'Pregnant + Lactating' => 'P+L',
                        'Pregnant and Lactating' => 'P+L',

                        'pregnant' => 'P',
                        'حامل' => 'P',

                        'lactating' => 'L',
                        'مرضع' => 'L',
                        'مرضعة' => 'L',
                        'Breastfeeding' => 'L',
                    ],
                    'gender' => [
                        'male' => 'M',
                        'female' => 'F',
                        'm' => 'M',
                        'f' => 'F',
                        'ذكر' => 'M',
                        'أنثى' => 'F',
                    ],
                    'child_age_lactated' => [
                        'أقل من 6 أشهر' => 'less_6_months',
                        'أقل من 6 شهور' => 'less_6_months',
                        'Less 6 Months' => 'less_6_months',
                        'Less than 6 Months' => 'less_6_months',
                        '<6 Months' => 'less_6_months',

                        '6-23 شهراً' => '6_23_months',
                        '6-23 شهر' => '6_23_months',
                        '6-23 Months' => '6_23_months',
                        '6-23' => '6_23_months',

                        '24-59 شهراً' => '24_59_months',
                        '24-59 شهر' => '24_59_months',
                        '24-59 Months' => '24_59_months',
                        '24-59' => '24_59_months',
                    ],
                    'shelter_name' => [
                        'مخيم مصعب' => 'mosaab_camp',
                        'مصعب' => 'mosaab_camp',
                        'Mosaab Camp' => 'mosaab_camp',
                        'Mossab Camp' => 'mosaab_camp',
                        'Musab Camp' => 'mosaab_camp',

                        'المحبة' => 'mahabba',
                        'مخيم المحبة' => 'mahabba',
                        'محبة' => 'mahabba',
                        'Mahabba' => 'mahabba',
                        'Mahabba Camp' => 'mahabba',

                        'السلام' => 'el_salam',
                        'مخيم السلام' => 'el_salam',
                        'El Salam' => 'el_salam',
                        'El Salam Camp' => 'el_salam',
                        'Al Salam Camp' => 'el_salam',

                        'القوقا' => 'el_qoqa',
                        'مخيم القوقا' => 'el_qoqa',
                        'El Qoqa' => 'el_qoqa',
                        'Al Qoqa' => 'el_qoqa',

                        'الحلو' => 'al_helou',
                        'Al Helou' => 'al_helou',
                        'El Helou' => 'al_helou',
                    ],
                    'consultation' => [
                        'التغذية التكميلية' => 'complementary_feeding',
                        'تغذية تكميلية' => 'complementary_feeding',
                        'Complementary feeding' => 'complementary_feeding',
                        'Complimentary Feeding' => 'complementary_feeding',
                        // Fifty three rows of one team's file spell it this way.
                        'Comlementary Feeding' => 'complementary_feeding',
                        'CF' => 'complementary_feeding',

                        'دعم الرضاعة الطبيعية' => 'bf_support',
                        'دعم الرضاعة' => 'bf_support',
                        'BF Support' => 'bf_support',
                        'Breastfeeding Support' => 'bf_support',
                        'BF' => 'bf_support',

                        'إعادة الإرضاع' => 'relactation',
                        'اعادة الرضاعة' => 'relactation',
                        'Relactation' => 'relactation',
                        'Re-lactation' => 'relactation',

                        'أخرى' => 'other',
                        'Other' => 'other',
                    ],
                    'status' => [
                        'تم التخريج' => 'discharged',
                        'تخريج' => 'discharged',
                        'Discharged' => 'discharged',
                        'Discharge' => 'discharged',

                        'تحت المتابعة' => 'under_follow_up',
                        'Under Follow-up' => 'under_follow_up',
                        'Under Follow up' => 'under_follow_up',
                        'Under Followup' => 'under_follow_up',
                    ],
                    'outcome' => [
                        'تحسنت' => 'improved',
                        'تحسن' => 'improved',
                        'Improved' => 'improved',
                        'Improvement' => 'improved',

                        'لم تتحسن' => 'dont_improve',
                        'لم يتحسن' => 'dont_improve',
                        "Don't Improve" => 'dont_improve',
                        'Not Improved' => 'dont_improve',
                        'No Improvement' => 'dont_improve',

                        'لا استجابة' => 'non_response',
                        'Non Response' => 'non_response',
                        'Non-Response' => 'non_response',
                        'No Response' => 'non_response',
                    ],
                    // Yes/No columns that are Selects rather than booleans, so
                    // castBoolean() never sees them and Y/N/1/0 would be
                    // refused without these.
                    'pregnancy' => [
                        'نعم' => 'yes',
                        'Yes' => 'yes',
                        'Y' => 'yes',
                        // The answer written as the state itself: a column
                        // headed "Pregnancy" answered "pregnant" is a yes.
                        'pregnant' => 'yes',
                        'حامل' => 'yes',
                        'True' => 'yes',
                        '1' => 'yes',

                        'لا' => 'no',
                        'No' => 'no',
                        'N' => 'no',
                        'False' => 'no',
                        '0' => 'no',
                    ],
                    'lactating' => [
                        'نعم' => 'yes',
                        'Yes' => 'yes',
                        'Y' => 'yes',
                        // As with pregnancy above: "lactated" under "Lactating"
                        // is how these sheets say yes.
                        'lactated' => 'yes',
                        'lactating' => 'yes',
                        'مرضع' => 'yes',
                        'مرضعة' => 'yes',
                        'True' => 'yes',
                        '1' => 'yes',

                        'لا' => 'no',
                        'No' => 'no',
                        'N' => 'no',
                        'False' => 'no',
                        '0' => 'no',
                    ],
                    // Feeding patterns as the older sheets spell them, and in
                    // Arabic - the stored values are English phrases with no
                    // translation key, so an Arabic sheet had no way in at all.
                    // Only unambiguous spellings are mapped: a bare
                    // "complementary feeding" does not say whether it is with
                    // BF or with formula, so it is refused rather than guessed
                    // at.
                    'feeding_type' => [
                        'رضاعة طبيعية حصرية' => 'Exclusive Breastfeeding',
                        'رضاعة طبيعية مطلقة' => 'Exclusive Breastfeeding',
                        'حليب صناعي' => 'Formula Feeding',
                        'رضاعة صناعية' => 'Formula Feeding',
                        'رضاعة مختلطة' => 'Mixed Feeding',
                        'رضاعة سائدة' => 'Predominant Feeding',
                        'تغذية تكميلية مع رضاعة طبيعية' => 'Complementary Feeding with BF',
                        'تغذية تكميلية مع حليب صناعي' => 'Complementary Feeding with Formula',
                        'فطام' => 'Weaning and On Family Foods',
                        'طعام الأسرة' => 'Weaning and On Family Foods',

                        'ebf' => 'Exclusive Breastfeeding',
                        'exclusive bf' => 'Exclusive Breastfeeding',
                        'exclusive breast feeding' => 'Exclusive Breastfeeding',
                        'exclusively bf' => 'Exclusive Breastfeeding',
                        'breastfeeding' => 'Exclusive Breastfeeding',
                        'breast feeding' => 'Exclusive Breastfeeding',
                        'formula' => 'Formula Feeding',
                        'formula milk' => 'Formula Feeding',
                        'artificial feeding' => 'Formula Feeding',
                        'mixed' => 'Mixed Feeding',
                        'mixed milk' => 'Mixed Feeding',
                        'mix feeding' => 'Mixed Feeding',
                        'predominant' => 'Predominant Feeding',
                        'predominant breastfeeding' => 'Predominant Feeding',
                        'complementary feeding with bf' => 'Complementary Feeding with BF',
                        'cf with bf' => 'Complementary Feeding with BF',
                        'cf with formula' => 'Complementary Feeding with Formula',
                        'weaning' => 'Weaning and On Family Foods',
                        'family foods' => 'Weaning and On Family Foods',
                        'on family foods' => 'Weaning and On Family Foods',
                    ],
                ],
                collapseWhitespace: ['feeding_type'],
            ),
            new self(
                key: 'follow_up_children',
                label: __('fields.follow_up_children'),
                model: FollowUpChild::class,
                export: FollowUpChildrenExport::class,
                resource: \App\Filament\Resources\FollowUpChildResource::class,
                permission: 'follow_up_children.import',
                filename: 'follow-up-children',
                // Age at admission is derived from DOB + admission date.
                computed: ['age_at_admission'],
                synonyms: [
                    'sex' => [
                        'ذكر' => 'M',
                        'Male' => 'M',
                        'M' => 'M',

                        'أنثى' => 'F',
                        'Female' => 'F',
                        'F' => 'F',
                    ],

                    // SAM and MAM are stored, shown and translated as the bare
                    // acronyms, so nothing but those two letters-triples was
                    // accepted - not the Arabic, and not the words they stand
                    // for.
                    'admitted_with' => [
                        'SAM' => 'SAM',
                        'S.A.M' => 'SAM',
                        'سام' => 'SAM',
                        'سوء تغذية حاد وخيم' => 'SAM',
                        'سوء تغذية حاد شديد' => 'SAM',
                        'Severe Acute Malnutrition' => 'SAM',

                        'MAM' => 'MAM',
                        'M.A.M' => 'MAM',
                        'مام' => 'MAM',
                        'سوء تغذية حاد متوسط' => 'MAM',
                        'Moderate Acute Malnutrition' => 'MAM',
                    ],

                    'discharge_outcome' => [
                        'شُفي' => 'cured',
                        'شفاء' => 'cured',
                        'Cured' => 'cured',
                        'Cure' => 'cured',
                        'Recovered' => 'cured',

                        'منقطع' => 'defaulted',
                        'انقطاع' => 'defaulted',
                        'Defaulted' => 'defaulted',
                        'Default' => 'defaulted',
                        'Defaulter' => 'defaulted',

                        'تخريج إلى OPT' => 'discharge_to_opt',
                        'OPT' => 'discharge_to_opt',
                        'Discharge to OPT' => 'discharge_to_opt',
                        'Discharged to OPT' => 'discharge_to_opt',

                        'تخريج إلى جهة أخرى' => 'discharge_to_other',
                        'Discharge to Other' => 'discharge_to_other',
                        'Discharged to Other' => 'discharge_to_other',

                        'متوفى' => 'died',
                        'توفي' => 'died',
                        'وفاة' => 'died',
                        'Died' => 'died',
                        'Death' => 'died',

                        'تحت المتابعة' => 'under_follow_up',
                        'Under Follow-up' => 'under_follow_up',
                        'Under Follow up' => 'under_follow_up',
                        'Under Followup' => 'under_follow_up',
                    ],
                ],
            ),
        ])->keyBy('key')->all();
    }

    public static function get(string $key): self
    {
        return static::all()[$key] ?? throw new \InvalidArgumentException("Unknown import module [{$key}].");
    }

    /**
     * A fresh export instance, used purely to read the column definitions.
     */
    public function exporter(): AbstractTableExport
    {
        /** @var class-string<Model> $model */
        $model = $this->model;

        return new ($this->export)($model::query());
    }

    /**
     * Import columns: every exported field except the system-computed ones.
     *
     * @return array<string>
     */
    public function fields(): array
    {
        return array_values(array_diff($this->exporter()->fields(), $this->computed));
    }

    public function booleanFields(): array
    {
        return $this->exporter()->booleanFields();
    }

    public function enumFields(): array
    {
        return $this->exporter()->enumFields();
    }

    /**
     * The module key the Super Admin notifications use, which is the model's
     * class basename rather than this registry's snake_case key.
     */
    public function moduleKeyForNotifications(): string
    {
        return class_basename($this->model);
    }

    /**
     * Whether this module carries the repeatable Follow Up visit columns.
     */
    public function hasVisits(): bool
    {
        return $this->model === FollowUpChild::class;
    }

    /**
     * Whether this module carries the numbered follow-up session columns.
     */
    public function hasFollowups(): bool
    {
        return $this->model === IndividualCounseling::class;
    }

    /**
     * Follow-up sessions one record may hold, when it holds any at all.
     */
    public function maxFollowups(): int
    {
        return IndividualCounseling::MAX_FOLLOWUP_SESSIONS;
    }
}
