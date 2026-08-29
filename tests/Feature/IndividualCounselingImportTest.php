<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Models\IndividualCounseling;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\ImportSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Import behaviour for the Individual Counseling module.
 *
 * Two jobs are being checked here. The numbered session columns have to land
 * in individual_counseling_followups as one row each — never as flat columns
 * on the record — and the file has to be refused if it carries more sessions
 * than a record may hold. Separately, the spellings the programme's own
 * workbooks are full of ("Follow" for "Follow up", "P/L", YES/yes, stray
 * double spaces) must be normalised rather than turned into rejected rows.
 */
class IndividualCounselingImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        \Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
    }

    private function definition(): ImportDefinition
    {
        return ImportDefinition::get('individual_counseling');
    }

    private function headings(): array
    {
        return (new ImportSchema($this->definition()))->headings();
    }

    /**
     * One data row, keyed by heading, on top of a minimal valid record.
     */
    private function row(array $cells = []): array
    {
        $headings = $this->headings();
        $row = array_fill(0, count($headings), null);

        $cells = array_merge([
            __('fields.date') => '2026-07-10',
            __('fields.child_name') => 'طفل الاستيراد',
            __('fields.mother_name') => 'أم الاستيراد',
            __('fields.mother_id_number') => '123456789',
        ], $cells);

        foreach ($cells as $heading => $value) {
            $index = array_search($heading, $headings, true);

            $this->assertNotFalse($index, "Heading [{$heading}] is not in the import template.");

            $row[$index] = $value;
        }

        return $row;
    }

    /**
     * Import a sheet made of the template headings plus the given data rows.
     *
     * @param  array<int, array>  $rows
     * @param  array<int, string>  $extraHeadings  appended after the template's own
     */
    private function import(array $rows, array $extraHeadings = []): array
    {
        $headings = array_merge($this->headings(), $extraHeadings);

        // Pad the data rows out to the widened heading row.
        $rows = array_map(
            fn (array $row): array => array_pad($row, count($headings), null),
            $rows,
        );

        $export = new class(array_merge([$headings], $rows)) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'ic-import-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            $this->definition(),
            \Storage::disk('local')->path($name),
        );
    }

    // -----------------------------------------------------------------
    // Follow-up sessions
    // -----------------------------------------------------------------

    public function test_numbered_session_columns_become_related_rows(): void
    {
        $result = $this->import([$this->row([
            __('fields.followup_date_n', ['n' => 1]) => '2026-08-01',
            __('fields.followup_assess_n', ['n' => 1]) => 'تقييم وتحليل أول',
            __('fields.followup_act_n', ['n' => 1]) => 'إجراء أول',
            __('fields.followup_date_n', ['n' => 3]) => '2026-08-20',
            __('fields.followup_assess_n', ['n' => 3]) => 'تقييم وتحليل ثالث',
            __('fields.followup_act_n', ['n' => 3]) => 'إجراء ثالث',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $sessions = IndividualCounseling::first()->followups;

        // The empty second group is a gap in the sheet, not a session held.
        $this->assertCount(2, $sessions);
        $this->assertSame('2026-08-01', $sessions[0]->follow_up_visit_date->format('Y-m-d'));
        $this->assertSame('تقييم وتحليل أول', $sessions[0]->assess_and_analyze);
        $this->assertSame('إجراء أول', $sessions[0]->act);
        $this->assertSame('2026-08-20', $sessions[1]->follow_up_visit_date->format('Y-m-d'));
        $this->assertSame('إجراء ثالث', $sessions[1]->act);
        $this->assertSame([1, 2], $sessions->pluck('sort_order')->all());
    }

    public function test_a_file_with_no_session_columns_filled_imports_without_sessions(): void
    {
        $result = $this->import([$this->row()]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseCount('individual_counseling_followups', 0);
    }

    public function test_the_template_offers_exactly_six_session_groups(): void
    {
        $headings = $this->headings();

        $this->assertContains(__('fields.followup_act_n', ['n' => 6]), $headings);
        $this->assertNotContains(__('fields.followup_date_n', ['n' => 7]), $headings);
    }

    public function test_a_file_carrying_a_seventh_session_is_refused(): void
    {
        $result = $this->import(
            [$this->row()],
            [__('fields.followup_date_n', ['n' => 7])],
        );

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('7', $result['errors'][0]);
        $this->assertStringContainsString('6', $result['errors'][0]);
        $this->assertDatabaseCount('individual_counselings', 0);
    }

    /**
     * The headings earlier exports produced still resolve, so a file downloaded
     * before a rename is not suddenly unreadable.
     */
    public function test_the_previous_session_headings_are_still_understood(): void
    {
        $schema = new ImportSchema($this->definition());

        $this->assertSame(
            ['type' => 'followup_assess', 'number' => 2],
            $schema->resolveHeading(__('fields.followup_assess_n_alt', ['n' => 2])),
        );

        // Both spellings the date column has carried.
        foreach (['followup_date_n_alt', 'followup_date_n_alt2'] as $key) {
            $this->assertSame(
                ['type' => 'followup_date', 'number' => 3],
                $schema->resolveHeading(__('fields.' . $key, ['n' => 3])),
                "Heading key [{$key}] must still resolve.",
            );
        }
    }

    /**
     * The template and the export must agree on the session headings, letter
     * for letter — the template is what people fill in and upload back.
     */
    public function test_the_session_date_column_is_named_after_the_visit_date(): void
    {
        app()->setLocale('en');

        $headings = $this->headings();
        $exported = (new \App\Exports\IndividualCounselingExport(IndividualCounseling::query()))->headings();

        foreach (range(1, 6) as $n) {
            $this->assertContains("Session {$n} Follow up visit date", $headings);
            $this->assertNotContains("Session {$n} Date", $headings);
        }

        // Group one is always exported, so its heading has to be the same one.
        $this->assertContains('Session 1 Follow up visit date', $exported);
        $this->assertNotContains('Session 1 Date', $exported);
    }

    // -----------------------------------------------------------------
    // Normalisation
    // -----------------------------------------------------------------

    public function test_follow_is_accepted_as_the_visit_type(): void
    {
        $result = $this->import([$this->row([
            __('fields.child_visit_type') => 'Follow',
            __('fields.mother_visit_type') => 'follow',
        ])]);

        $this->assertSame([], $result['errors']);

        $record = IndividualCounseling::first();

        $this->assertSame('follow_up', $record->child_visit_type);
        $this->assertSame('follow_up', $record->mother_visit_type);
    }

    public function test_mixed_case_yes_and_no_are_accepted(): void
    {
        $result = $this->import([$this->row([
            __('fields.iycf_form_filled') => 'YES',
            __('fields.pregnancy') => 'yes',
            __('fields.lactating') => 'No',
        ])]);

        $this->assertSame([], $result['errors']);

        $record = IndividualCounseling::first();

        $this->assertTrue($record->iycf_form_filled);
        $this->assertSame('yes', $record->pregnancy);
        $this->assertSame('no', $record->lactating);
    }

    public function test_stray_spaces_in_the_feeding_type_are_squeezed(): void
    {
        $result = $this->import([$this->row([
            __('fields.feeding_type') => "  Mixed   Feeding  ",
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Mixed Feeding', IndividualCounseling::first()->feeding_type);
    }

    /**
     * Unambiguous older spellings are mapped onto the official option; the
     * value that lands in the database is always one of the seven.
     */
    public function test_older_feeding_spellings_are_mapped_onto_the_official_option(): void
    {
        $cases = [
            'mixed milk' => 'Mixed Feeding',
            'formula milk' => 'Formula Feeding',
            'EBF' => 'Exclusive Breastfeeding',
            'weaning' => 'Weaning and On Family Foods',
            'Exclusive Breastfeeding ' => 'Exclusive Breastfeeding',
        ];

        foreach ($cases as $typed => $expected) {
            IndividualCounseling::query()->forceDelete();

            $result = $this->import([$this->row([__('fields.feeding_type') => $typed])]);

            $this->assertSame([], $result['errors'], "[{$typed}] should be understood.");
            $this->assertSame($expected, IndividualCounseling::first()->feeding_type);
        }
    }

    /**
     * A spelling that does not say which of the seven it means is refused
     * rather than guessed at — "complementary feeding" could be either the BF
     * or the formula variant.
     */
    public function test_an_ambiguous_feeding_value_is_refused_with_the_list(): void
    {
        app()->setLocale('ar');

        $result = $this->import([$this->row([
            __('fields.feeding_type') => 'complementary feeding',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('الصف 2', $result['errors'][0]);
        $this->assertStringContainsString(__('fields.feeding_type'), $result['errors'][0]);
        $this->assertStringContainsString('Complementary Feeding with BF', $result['errors'][0]);
        $this->assertStringContainsString('Complementary Feeding with Formula', $result['errors'][0]);

        $this->assertDatabaseCount('individual_counselings', 0);
    }

    public function test_every_official_feeding_option_imports_as_itself(): void
    {
        foreach (\App\Filament\Resources\IndividualCounselingResource::feedingTypeValues() as $value) {
            IndividualCounseling::query()->forceDelete();

            $result = $this->import([$this->row([__('fields.feeding_type') => $value])]);

            $this->assertSame([], $result['errors'], "[{$value}] should import.");
            $this->assertSame($value, IndividualCounseling::first()->feeding_type);
        }
    }

    public function test_the_composite_p_slash_l_is_accepted(): void
    {
        $result = $this->import([$this->row([__('fields.p_l') => 'P/L'])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('P+L', IndividualCounseling::first()->p_l);
    }

    public function test_gender_is_imported_from_the_short_code(): void
    {
        $result = $this->import([$this->row([__('fields.gender') => 'F'])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('F', IndividualCounseling::first()->gender);
    }

    public function test_al_helou_is_accepted_as_a_shelter(): void
    {
        $result = $this->import([$this->row([__('fields.shelter_name') => 'Al Helou'])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('al_helou', IndividualCounseling::first()->shelter_name);
    }

    /**
     * Assess is prose, not an option: whatever the sheet says goes in as-is.
     */
    public function test_any_assessment_text_is_accepted(): void
    {
        $result = $this->import([$this->row([
            __('fields.assess') => 'الطفلة تعاني من نقص واضح في الوزن مع صعوبة في الرضاعة، وتقييم مطوّل مكتوب بحرية دون أي قائمة خيارات جاهزة.',
            __('fields.analyze') => 'تحليل حر كذلك',
        ])]);

        $this->assertSame([], $result['errors']);

        $record = IndividualCounseling::first();

        $this->assertSame('الطفلة تعاني من نقص واضح في الوزن مع صعوبة في الرضاعة، وتقييم مطوّل مكتوب بحرية دون أي قائمة خيارات جاهزة.', $record->assess);
        $this->assertSame('تحليل حر كذلك', $record->analyze);
    }

    // -----------------------------------------------------------------
    // Rejection
    // -----------------------------------------------------------------

    /**
     * A bare age in months is the single most common thing typed into the
     * child age/lactated column, and it is not one of the three categories.
     */
    public function test_a_bare_number_in_child_age_lactated_is_rejected_by_row_and_column(): void
    {
        app()->setLocale('ar');

        $result = $this->import([$this->row([__('fields.child_age_lactated') => '18'])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);

        // The row number and the column name both have to be in the message.
        $this->assertStringContainsString('الصف 2', $result['errors'][0]);
        $this->assertStringContainsString(__('fields.child_age_lactated'), $result['errors'][0]);
        $this->assertStringContainsString(__('fields.6_23_months'), $result['errors'][0]);

        $this->assertDatabaseCount('individual_counselings', 0);
    }

    public function test_the_three_child_age_categories_are_accepted(): void
    {
        foreach (['less_6_months', '6_23_months', '24_59_months'] as $i => $category) {
            IndividualCounseling::query()->forceDelete();

            $result = $this->import([$this->row([
                __('fields.child_age_lactated') => __('fields.' . $category),
            ])]);

            $this->assertSame([], $result['errors'], "Category [{$category}] should be accepted.");
            $this->assertSame($category, IndividualCounseling::first()->child_age_lactated);
        }
    }

    public function test_an_unreadable_option_cancels_the_whole_file(): void
    {
        $result = $this->import([
            $this->row(),
            $this->row([__('fields.status') => 'كلام غير معروف', __('fields.mother_id_number') => '987654321']),
        ]);

        $this->assertSame(0, $result['imported']);
        $this->assertDatabaseCount('individual_counselings', 0);
    }

    // -----------------------------------------------------------------
    // Round trip
    // -----------------------------------------------------------------

    /**
     * What the export writes, the import has to be able to read back — session
     * groups and the separate base-visit columns alike.
     */
    public function test_an_exported_file_imports_back_unchanged(): void
    {
        $original = IndividualCounseling::create([
            'date' => '2026-07-10',
            'child_name' => 'طفل التصدير',
            'child_visit_type' => 'follow_up',
            'gender' => 'F',
            'p_l' => 'P+L',
            'shelter_name' => 'al_helou',
            'child_age_lactated' => '6_23_months',
            'feeding_type' => 'Mixed Feeding',
            'mother_id_number' => '111222333',
            'mother_name' => 'أم التصدير',
            'assess' => 'الطفلة تعاني من نقص واضح في الوزن مع صعوبة في الرضاعة، وتقييم مطوّل مكتوب بحرية دون أي قائمة خيارات جاهزة.',
            'analyze' => 'Base analyze',
            'act' => 'Base act',
        ]);

        $original->followups()->create([
            'sort_order' => 1,
            'follow_up_visit_date' => '2026-08-01',
            'assess_and_analyze' => 'مدمج',
            'act' => 'إجراء الجلسة',
        ]);

        $path = \Storage::disk('local')->path('ic-roundtrip.xlsx');

        file_put_contents($path, Excel::raw(
            new \App\Exports\IndividualCounselingExport(IndividualCounseling::query()),
            \Maatwebsite\Excel\Excel::XLSX,
        ));

        IndividualCounseling::query()->forceDelete();

        $result = app(ExcelImportService::class)->import($this->definition(), $path);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $reimported = IndividualCounseling::first();

        $this->assertSame('follow_up', $reimported->child_visit_type);
        $this->assertSame('F', $reimported->gender);
        $this->assertSame('P+L', $reimported->p_l);
        $this->assertSame('al_helou', $reimported->shelter_name);
        $this->assertSame('الطفلة تعاني من نقص واضح في الوزن مع صعوبة في الرضاعة، وتقييم مطوّل مكتوب بحرية دون أي قائمة خيارات جاهزة.', $reimported->assess);
        $this->assertSame('Base analyze', $reimported->analyze);
        $this->assertSame('Base act', $reimported->act);

        $this->assertCount(1, $reimported->followups);
        $this->assertSame('2026-08-01', $reimported->followups[0]->follow_up_visit_date->format('Y-m-d'));
        $this->assertSame('مدمج', $reimported->followups[0]->assess_and_analyze);
        $this->assertSame('إجراء الجلسة', $reimported->followups[0]->act);
    }
}
