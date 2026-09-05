<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\ImportSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Bilingual reading of the Select columns of every module except Pregnant /
 * Lactating Women, which has its own test alongside its own synonym map.
 *
 * The workbooks arrive written in Arabic from one team and in English from the
 * next, and the same column is spelled "Follow-up", "F/U" and "متابعة" in three
 * files from the same week. All of them have to land on one stored value.
 *
 * What must not happen is the other half of that: a real misspelling being
 * quietly read as whatever option it looks closest to. Every module below is
 * checked in three ways - a purely Arabic sheet, a purely English sheet, and a
 * value that is simply wrong - because a map that accepts everything is worth
 * no more than one that accepts nothing.
 *
 * The maps live in ImportDefinition, and are exercised here through the real
 * ExcelImportService rather than by reading the array back: a map that is not
 * handed to the definition is dead code, which is exactly how this went wrong
 * on the first module it was written for.
 */
class ModuleImportSynonymsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        \Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    private function headings(string $key): array
    {
        return (new ImportSchema(ImportDefinition::get($key)))->headings();
    }

    /**
     * One data row for a module, keyed by heading, over a minimal valid record.
     *
     * @param  array<string, mixed>  $cells
     */
    private function row(string $key, array $base, array $cells = []): array
    {
        $headings = $this->headings($key);
        $row = array_fill(0, count($headings), null);

        foreach (array_merge($base, $cells) as $heading => $value) {
            $index = array_search($heading, $headings, true);

            $this->assertNotFalse($index, "Heading [{$heading}] is not in the {$key} template.");

            $row[$index] = $value;
        }

        return $row;
    }

    /**
     * Import a sheet made of a module's headings plus the given data rows.
     *
     * @param  array<int, array>  $rows
     */
    private function import(string $key, array $rows): array
    {
        $definition = ImportDefinition::get($key);

        $export = new class(array_merge([$this->headings($key)], $rows)) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = $key . '-import-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            $definition,
            \Storage::disk('local')->path($name),
        );
    }

    /**
     * Every module's map has to actually reach the definition. Building one and
     * forgetting to pass it is the failure this whole test exists for.
     */
    public function test_every_module_hands_its_synonym_map_to_the_import_definition(): void
    {
        foreach (['children', 'pregnant', 'group_sessions', 'mother_to_mother', 'individual_counseling', 'follow_up_children'] as $key) {
            $synonyms = ImportDefinition::get($key)->synonyms;

            $this->assertNotSame([], $synonyms, "Module [{$key}] carries no synonyms at all.");

            foreach ($synonyms as $field => $map) {
                $this->assertNotSame([], $map, "Module [{$key}] field [{$field}] has an empty map.");
            }
        }
    }

    /**
     * Every entry in every map has to be able to do something.
     *
     * A map is only consulted for a column the module actually imports, and an
     * alias is only honoured when the value it points at is a real option of
     * that column - castEnum() checks array_key_exists($stored, $options)
     * before accepting it. So an entry naming a column the module does not
     * have, or a value its form does not offer, is dead: it will never fire,
     * and nothing about the import will look wrong while it sits there. That is
     * the same shape of mistake as writing a map and never passing it, one
     * level down, and it is what this catches.
     */
    public function test_no_map_names_a_column_or_a_value_the_module_does_not_have(): void
    {
        foreach (['children', 'pregnant', 'group_sessions', 'mother_to_mother', 'individual_counseling', 'follow_up_children'] as $key) {
            $definition = ImportDefinition::get($key);
            $schema = new ImportSchema($definition);
            $fields = $definition->fields();

            foreach ($definition->synonyms as $field => $map) {
                $this->assertContains(
                    $field,
                    $fields,
                    "[{$key}] maps a column [{$field}] that this module does not import.",
                );

                $options = $schema->optionsFor($field);

                if ($options === null || $options === []) {
                    // A boolean column, or a Select whose options are dynamic.
                    // castEnum() never reaches the map for those.
                    continue;
                }

                foreach ($map as $alias => $stored) {
                    $this->assertArrayHasKey(
                        $stored,
                        $options,
                        "[{$key}.{$field}] maps [{$alias}] onto [{$stored}], which is not an option of that column.",
                    );
                }
            }
        }
    }

    // =================================================================
    // Children
    // =================================================================

    private function childRow(array $cells = []): array
    {
        return $this->row('children', [
            __('fields.name') => 'طفل الاستيراد',
            __('fields.child_id') => '123456789',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => '2026-08-20',
            __('fields.governorate') => 'gaza',
            __('fields.sex') => 'ذكر',
            __('fields.muac_mm') => '130',
        ], $cells);
    }

    public function test_children_accept_a_purely_arabic_sheet(): void
    {
        $result = $this->import('children', [$this->childRow([
            __('fields.visit_type') => 'جديد',
            __('fields.sex') => 'ذكر',
            __('fields.type_of_site') => 'مخيم السلام',
            __('fields.mother_marital_status') => 'متزوجة',
            __('fields.head_of_household_sex') => 'أنثى',
            __('fields.income_source') => 'أونروا',
            __('fields.disability_cause') => 'الحرب',
            __('fields.is_pwd') => 'نعم',
            __('fields.is_displaced') => 'لا',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $child = Child::first();

        $this->assertSame('male', $child->sex);
        $this->assertSame('El Salam Camp', $child->type_of_site);
        $this->assertSame('متزوجة', $child->mother_marital_status);
        $this->assertSame('female', $child->head_of_household_sex);
        $this->assertSame('unrwa', $child->income_source);
        $this->assertSame('war', $child->disability_cause);
        $this->assertTrue((bool) $child->is_pwd);
        $this->assertFalse((bool) $child->is_displaced);
    }

    public function test_children_accept_a_purely_english_sheet(): void
    {
        $result = $this->import('children', [$this->childRow([
            __('fields.visit_type') => 'New',
            __('fields.sex') => 'Male',
            __('fields.type_of_site') => 'Mosaab Camp',
            __('fields.mother_marital_status') => 'Widowed',
            __('fields.head_of_household_sex') => 'Female',
            __('fields.income_source') => 'UNRWA',
            __('fields.disability_cause') => 'War',
            __('fields.is_pwd') => 'Yes',
            __('fields.is_displaced') => 'No',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $child = Child::first();

        $this->assertSame('male', $child->sex);
        $this->assertSame('Mossab Camp', $child->type_of_site);
        $this->assertSame('أرملة', $child->mother_marital_status);
        $this->assertSame('female', $child->head_of_household_sex);
        $this->assertSame('unrwa', $child->income_source);
        $this->assertSame('war', $child->disability_cause);
        $this->assertTrue((bool) $child->is_pwd);
    }

    public function test_children_accept_the_one_letter_codes_the_workbooks_use(): void
    {
        $result = $this->import('children', [$this->childRow([
            __('fields.sex') => 'F',
            __('fields.head_of_household_sex') => 'M',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('female', Child::first()->sex);
        $this->assertSame('male', Child::first()->head_of_household_sex);
    }

    public function test_children_refuse_a_value_that_is_simply_wrong(): void
    {
        $result = $this->import('children', [$this->childRow([
            __('fields.type_of_site') => 'مخيم لا وجود له',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);

        $message = $result['errors'][0];

        // The message has to name the row, the column, the refused value and
        // what would have been accepted - a sheet of two thousand rows is not
        // searchable by "a column is wrong somewhere".
        $this->assertStringContainsString('2', $message);
        $this->assertStringContainsString(__('fields.type_of_site'), $message);
        $this->assertStringContainsString('مخيم لا وجود له', $message);
        $this->assertStringContainsString('مخيم السلام', $message);
    }

    public function test_children_refuse_a_near_miss_rather_than_guessing_at_it(): void
    {
        // "Mahaba" is one letter off "Mahabba". Literal matching only, so it is
        // refused: a guess here silently files a child at the wrong site.
        $result = $this->import('children', [$this->childRow([
            __('fields.type_of_site') => 'Mahaba',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
    }

    public function test_children_leave_optional_select_columns_blank(): void
    {
        // Half of these columns are optional, and a historical sheet routinely
        // has them empty. A blank cell is not a value to validate.
        $result = $this->import('children', [$this->childRow([
            __('fields.type_of_site') => null,
            __('fields.mother_marital_status') => '',
            __('fields.income_source') => '   ',
            __('fields.disability_cause') => null,
            __('fields.head_of_household_sex') => null,
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $child = Child::first();

        $this->assertNull($child->type_of_site);
        $this->assertNull($child->mother_marital_status);
        $this->assertNull($child->income_source);
    }

    public function test_children_read_a_yes_no_column_stored_as_a_real_excel_boolean(): void
    {
        // Not the words "Yes" and "No" - the boolean cells a workbook holds
        // when its Yes/No columns were filled in from a checkbox or produced
        // by another system. PHP receives true and false, and (string) false
        // is the empty string, so every row answering "no" was refused with
        // "must be Yes / No" while the identical column written as text
        // imported without a murmur.
        $result = $this->import('children', [$this->childRow([
            __('fields.is_enrolled_bsfp') => false,
            __('fields.has_unaccompanied_children') => false,
            __('fields.has_released_children') => false,
            __('fields.has_injured_after_oct7') => false,
            __('fields.has_family_disability') => false,
            __('fields.is_pwd') => true,
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $child = Child::first();

        $this->assertFalse((bool) $child->is_enrolled_bsfp);
        $this->assertFalse((bool) $child->has_unaccompanied_children);
        $this->assertFalse((bool) $child->has_released_children);
        $this->assertFalse((bool) $child->has_injured_after_oct7);
        $this->assertFalse((bool) $child->has_family_disability);
        $this->assertTrue((bool) $child->is_pwd);
    }

    public function test_children_read_a_yes_no_column_written_as_a_decimal(): void
    {
        // "1.0" and "0.00" are the same answer a spreadsheet writes when the
        // column was formatted as a number. A 2 is not an answer at all and
        // still fails, so the column has not become a free-for-all.
        $accepted = $this->import('children', [$this->childRow([
            __('fields.is_enrolled_bsfp') => '1.0',
            __('fields.is_sick_last_6_months') => '0.00',
        ])]);

        $this->assertSame([], $accepted['errors']);
        $this->assertTrue((bool) Child::first()->is_enrolled_bsfp);
        $this->assertFalse((bool) Child::first()->is_sick_last_6_months);

        $refused = $this->import('children', [$this->childRow([
            __('fields.child_id') => '987654321',
            __('fields.is_enrolled_bsfp') => '2',
        ])]);

        $this->assertSame(0, $refused['imported']);
        $this->assertCount(1, $refused['errors']);
    }

    public function test_children_read_the_income_and_disability_spellings_the_files_use(): void
    {
        // The two columns the sheets write in prose rather than in the form's
        // vocabulary. The bracketed spelling was already accepted and told us
        // nothing about the same words without brackets, which is what the
        // files are mostly written in: normalising unifies letter forms, it
        // does not remove brackets or the definite article.
        $result = $this->import('children', [$this->childRow([
            __('fields.income_source') => 'وكالة أونروا',
            __('fields.disability_cause') => 'إصابة حرب',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('unrwa', Child::first()->income_source);
        $this->assertSame('war', Child::first()->disability_cause);

        foreach ([
            'الحكومة' => 'government',
            'حكومية' => 'government',
            'الوكالة' => 'unrwa',
            'وكالة الغوث' => 'unrwa',
            'غير ذلك' => 'other',
            'Others' => 'other',
        ] as $spelling => $stored) {
            Child::query()->forceDelete();

            $result = $this->import('children', [$this->childRow([
                __('fields.income_source') => $spelling,
            ])]);

            $this->assertSame([], $result['errors'], "[{$spelling}] was refused.");
            $this->assertSame($stored, Child::first()->income_source);
        }
    }

    public function test_children_still_refuse_an_income_source_that_is_simply_wrong(): void
    {
        $result = $this->import('children', [$this->childRow([
            __('fields.income_source') => 'مصدر لا وجود له',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString(__('fields.income_source'), $result['errors'][0]);
    }

    public function test_children_read_a_hand_typed_date_day_first(): void
    {
        // The same reading the Pregnant / Lactating workbooks needed, and for
        // the same reason: Carbon reads a slashed date month-first, so
        // "31/12/1990" was refused outright - which is what failed rows on
        // "Invalid date for Mother Date of Birth" - and "7/12/2020" imported
        // as the 12th of July with nothing said.
        $result = $this->import('children', [$this->childRow([
            __('fields.date_of_reporting') => '15/03/2025',
            __('fields.date_of_birth') => '7/12/2020',
            __('fields.mother_date_of_birth') => '31/12/1990',
        ])]);

        $this->assertSame([], $result['errors']);

        $child = Child::first();

        $this->assertSame('2025-03-15', $child->date_of_reporting->format('Y-m-d'));
        $this->assertSame('2020-12-07', $child->date_of_birth->format('Y-m-d'));
        $this->assertSame('1990-12-31', $child->mother_date_of_birth->format('Y-m-d'));
    }

    public function test_children_accept_an_empty_mother_date_of_birth(): void
    {
        // The mother's details are optional on a record about the child, and a
        // historical sheet leaves this column empty in every shape a sheet has
        // for "nothing here".
        foreach (['', '   ', "\u{00A0}", '-', '0', '00/00/0000'] as $blank) {
            Child::query()->forceDelete();

            $result = $this->import('children', [$this->childRow([
                __('fields.mother_date_of_birth') => $blank,
            ])]);

            $this->assertSame([], $result['errors'], 'A blank mother date of birth was refused.');
            $this->assertNull(Child::first()->mother_date_of_birth);
        }
    }

    public function test_an_unreadable_mother_date_of_birth_costs_the_cell_not_the_row(): void
    {
        $result = $this->import('children', [$this->childRow([
            __('fields.mother_date_of_birth') => '31/4/2025',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertNull(Child::first()->mother_date_of_birth);
    }

    public function test_an_unreadable_date_in_a_column_the_record_needs_still_fails_the_row(): void
    {
        // The reporting date is NOT NULL and the child's own date of birth is
        // what the age in months is computed from. Neither may be silently
        // emptied the way the mother's may.
        foreach ([__('fields.date_of_reporting'), __('fields.date_of_birth')] as $heading) {
            $result = $this->import('children', [$this->childRow([$heading => 'not a date'])]);

            $this->assertSame(0, $result['imported'], "[{$heading}] was accepted unread.");
            $this->assertNotSame([], $result['errors']);
        }
    }

    // =================================================================
    // Group Sessions
    // =================================================================

    private function groupSessionRow(array $cells = []): array
    {
        return $this->row('group_sessions', [
            __('fields.session_date') => '2026-08-20',
            __('fields.session_group_number') => '1',
            __('fields.session_subject') => 'bf_support',
            __('fields.locality') => 'tal_al_hawa',
            __('fields.shelter_name') => 'mosaab_camp',
            __('fields.id_number') => '123456789',
            __('fields.full_name_ar') => 'مشاركة الاستيراد',
            __('fields.category') => 'grandmothers',
            __('fields.marital_status') => 'married',
        ], $cells);
    }

    public function test_group_sessions_accept_a_purely_arabic_sheet(): void
    {
        $result = $this->import('group_sessions', [$this->groupSessionRow([
            __('fields.session_subject') => 'التغذية التكميلية',
            __('fields.locality') => 'الشاطئ',
            __('fields.shelter_name') => 'مخيم السلام',
            __('fields.visit_type') => 'متابعة',
            __('fields.category') => 'الجدات',
            __('fields.marital_status') => 'أرملة',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $session = \App\Models\GroupSession::first();

        $this->assertSame('complimentary_feeding', $session->session_subject);
        $this->assertSame('el_shatee', $session->locality);
        $this->assertSame('el_salam', $session->shelter_name);
        $this->assertSame('grandmothers', $session->category);
        $this->assertSame('widow', $session->marital_status);
    }

    public function test_group_sessions_accept_a_purely_english_sheet(): void
    {
        $result = $this->import('group_sessions', [$this->groupSessionRow([
            // Spelled the way the term actually is, not the way the form has it.
            __('fields.session_subject') => 'Complementary Feeding',
            __('fields.locality') => 'Tal El Hawa',
            __('fields.shelter_name') => 'Mossab Camp',
            __('fields.visit_type') => 'F/U',
            __('fields.category') => 'Reproductive Age',
            __('fields.marital_status') => 'Widowed',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $session = \App\Models\GroupSession::first();

        $this->assertSame('complimentary_feeding', $session->session_subject);
        $this->assertSame('tal_al_hawa', $session->locality);
        $this->assertSame('mosaab_camp', $session->shelter_name);
        $this->assertSame('reproductive_age', $session->category);
        $this->assertSame('widow', $session->marital_status);
    }

    public function test_group_sessions_accept_the_masculine_marital_forms(): void
    {
        // The category list includes men, so "متزوج" is as ordinary a cell
        // here as "متزوجة" and used to be refused outright.
        $result = $this->import('group_sessions', [$this->groupSessionRow([
            __('fields.category') => 'ذكور',
            __('fields.marital_status') => 'متزوج',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('male', \App\Models\GroupSession::first()->category);
        $this->assertSame('married', \App\Models\GroupSession::first()->marital_status);
    }

    public function test_group_sessions_refuse_a_value_that_is_simply_wrong(): void
    {
        $result = $this->import('group_sessions', [$this->groupSessionRow([
            __('fields.category') => 'فئة غير موجودة',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString(__('fields.category'), $result['errors'][0]);
        $this->assertStringContainsString('فئة غير موجودة', $result['errors'][0]);
    }

    // =================================================================
    // Mother to Mother
    // =================================================================

    private function motherToMotherRow(array $cells = []): array
    {
        return $this->row('mother_to_mother', [
            __('fields.session_date') => '2026-08-20',
            __('fields.session_group_number') => '1',
            __('fields.session_subject') => 'bf_support',
            __('fields.locality') => 'mosaab_camp',
            __('fields.shelter_name') => 'مخيم مصعب',
            __('fields.id_number') => '123456789',
            __('fields.full_name_ar') => 'مشاركة الاستيراد',
            __('fields.category') => 'grandmothers',
            __('fields.marital_status') => 'married',
        ], $cells);
    }

    public function test_mother_to_mother_accepts_a_purely_arabic_sheet(): void
    {
        $result = $this->import('mother_to_mother', [$this->motherToMotherRow([
            __('fields.session_subject') => 'إعادة الإرضاع',
            __('fields.locality') => 'مخيم المحبة',
            __('fields.visit_type') => 'متابعة',
            __('fields.category') => 'حوامل',
            __('fields.marital_status') => 'منفصلة',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $session = \App\Models\MotherToMotherSession::first();

        $this->assertSame('relactation', $session->session_subject);
        $this->assertSame('mahabba_camp', $session->locality);
        $this->assertSame('pregnant', $session->category);
        $this->assertSame('separated', $session->marital_status);
    }

    public function test_mother_to_mother_accepts_a_purely_english_sheet(): void
    {
        $result = $this->import('mother_to_mother', [$this->motherToMotherRow([
            __('fields.session_subject') => 'Re-lactation',
            __('fields.locality') => 'Al Salam Camp',
            __('fields.visit_type') => 'Follow up',
            __('fields.category') => 'Caregiver <6 Months',
            __('fields.marital_status') => 'Widow',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $session = \App\Models\MotherToMotherSession::first();

        $this->assertSame('relactation', $session->session_subject);
        $this->assertSame('el_salam_camp', $session->locality);
        $this->assertSame('caregiver_child_under_6_months', $session->category);
        $this->assertSame('widow', $session->marital_status);
    }

    /**
     * The two modules both have a `locality`, and it means different things:
     * neighbourhoods in the group sessions, camps here. Neither map may accept
     * the other's vocabulary, or a session lands in the wrong place entirely.
     */
    public function test_the_two_locality_columns_do_not_accept_each_others_vocabulary(): void
    {
        $refusedHere = $this->import('mother_to_mother', [$this->motherToMotherRow([
            __('fields.locality') => 'الشاطئ',
        ])]);

        $this->assertSame(0, $refusedHere['imported']);

        $refusedThere = $this->import('group_sessions', [$this->groupSessionRow([
            __('fields.locality') => 'مخيم المحبة',
        ])]);

        $this->assertSame(0, $refusedThere['imported']);
    }

    public function test_mother_to_mother_refuses_a_value_that_is_simply_wrong(): void
    {
        $result = $this->import('mother_to_mother', [$this->motherToMotherRow([
            __('fields.session_subject') => 'موضوع غير معروف',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('موضوع غير معروف', $result['errors'][0]);
    }

    // =================================================================
    // Individual Counseling — the map existed but covered four columns
    // =================================================================

    private function counselingRow(array $cells = []): array
    {
        return $this->row('individual_counseling', [
            __('fields.date') => '2026-08-20',
            __('fields.child_name') => 'طفل الإرشاد',
            __('fields.mother_id_number') => '123456789',
            __('fields.mother_name') => 'أم الإرشاد',
        ], $cells);
    }

    public function test_individual_counseling_accepts_a_purely_arabic_sheet(): void
    {
        $result = $this->import('individual_counseling', [$this->counselingRow([
            __('fields.child_visit_type') => 'متابعة',
            __('fields.gender') => 'أنثى',
            __('fields.child_age_lactated') => 'أقل من 6 أشهر',
            __('fields.shelter_name') => 'مخيم المحبة',
            __('fields.consultation') => 'دعم الرضاعة',
            __('fields.status') => 'تحت المتابعة',
            __('fields.outcome') => 'تحسنت',
            __('fields.p_l') => 'حامل ومرضع',
            __('fields.pregnancy') => 'نعم',
            __('fields.lactating') => 'لا',
            // The stored values are English phrases with no translation key,
            // so before this an Arabic sheet had no way to fill this column.
            __('fields.feeding_type') => 'رضاعة مختلطة',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $record = \App\Models\IndividualCounseling::first();

        $this->assertSame('follow_up', $record->child_visit_type);
        $this->assertSame('F', $record->gender);
        $this->assertSame('less_6_months', $record->child_age_lactated);
        $this->assertSame('mahabba', $record->shelter_name);
        $this->assertSame('bf_support', $record->consultation);
        $this->assertSame('under_follow_up', $record->status);
        $this->assertSame('improved', $record->outcome);
        $this->assertSame('P+L', $record->p_l);
        $this->assertSame('yes', $record->pregnancy);
        $this->assertSame('no', $record->lactating);
        $this->assertSame('Mixed Feeding', $record->feeding_type);
    }

    public function test_individual_counseling_accepts_a_purely_english_sheet(): void
    {
        $result = $this->import('individual_counseling', [$this->counselingRow([
            __('fields.child_visit_type') => 'F/U',
            __('fields.gender') => 'Male',
            __('fields.child_age_lactated') => '6-23 Months',
            __('fields.shelter_name') => 'Al Helou',
            __('fields.consultation') => 'Complimentary Feeding',
            __('fields.status') => 'Discharge',
            __('fields.outcome') => 'No Response',
            __('fields.p_l') => 'Pregnant',
            // Selects, not booleans, so Y/N never reached castBoolean().
            __('fields.pregnancy') => 'Y',
            __('fields.lactating') => 'N',
            __('fields.feeding_type') => 'EBF',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $record = \App\Models\IndividualCounseling::first();

        $this->assertSame('follow_up', $record->child_visit_type);
        $this->assertSame('M', $record->gender);
        $this->assertSame('6_23_months', $record->child_age_lactated);
        $this->assertSame('al_helou', $record->shelter_name);
        $this->assertSame('complementary_feeding', $record->consultation);
        $this->assertSame('discharged', $record->status);
        $this->assertSame('non_response', $record->outcome);
        $this->assertSame('P', $record->p_l);
        $this->assertSame('yes', $record->pregnancy);
        $this->assertSame('no', $record->lactating);
        $this->assertSame('Exclusive Breastfeeding', $record->feeding_type);
    }

    public function test_individual_counseling_still_refuses_an_ambiguous_feeding_type(): void
    {
        // A bare "complementary feeding" does not say whether it is with BF or
        // with formula. Guessing would file the wrong one; it stays refused.
        $result = $this->import('individual_counseling', [$this->counselingRow([
            __('fields.feeding_type') => 'Complementary Feeding',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
    }

    // =================================================================
    // Follow Up Child
    // =================================================================

    private function followUpChildRow(array $cells = []): array
    {
        return $this->row('follow_up_children', [
            __('fields.id_number') => '123456789',
            __('fields.child_name') => 'طفل المتابعة',
        ], $cells);
    }

    public function test_follow_up_children_accept_a_purely_arabic_sheet(): void
    {
        $result = $this->import('follow_up_children', [$this->followUpChildRow([
            __('fields.sex') => 'أنثى',
            __('fields.admitted_with') => 'سوء تغذية حاد وخيم',
            __('fields.discharge_outcome') => 'تحت المتابعة',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $record = \App\Models\FollowUpChild::first();

        $this->assertSame('F', $record->sex);
        $this->assertSame('SAM', $record->admitted_with);
        $this->assertSame('under_follow_up', $record->discharge_outcome);
    }

    public function test_follow_up_children_accept_a_purely_english_sheet(): void
    {
        $result = $this->import('follow_up_children', [$this->followUpChildRow([
            __('fields.sex') => 'Male',
            __('fields.admitted_with') => 'Moderate Acute Malnutrition',
            __('fields.discharge_outcome') => 'Recovered',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $record = \App\Models\FollowUpChild::first();

        $this->assertSame('M', $record->sex);
        $this->assertSame('MAM', $record->admitted_with);
        $this->assertSame('cured', $record->discharge_outcome);
    }

    public function test_follow_up_children_refuse_a_value_that_is_simply_wrong(): void
    {
        $result = $this->import('follow_up_children', [$this->followUpChildRow([
            __('fields.discharge_outcome') => 'نتيجة غير معروفة',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('نتيجة غير معروفة', $result['errors'][0]);
    }
}
