<?php

namespace Tests\Feature;

use App\Exports\IndividualCounselingExport;
use App\Models\IndividualCounseling;
use App\Models\IndividualCounselingFollowup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Export behaviour for the Individual Counseling follow-up session columns.
 *
 * A session that carries no date (or no assessment, or no action) must leave
 * the matching cell genuinely empty - never the string "null", never a broken
 * date like 0000-00-00 - while the column layout stays exactly as it is.
 */
class IndividualCounselingExportTest extends TestCase
{
    use RefreshDatabase;

    private function counseling(array $attributes = []): IndividualCounseling
    {
        return IndividualCounseling::create(array_merge([
            'date' => '2026-07-10',
            'child_name' => 'Test child',
            'child_visit_type' => 'new',
            'child_dob' => '2025-07-10',
            'gender' => 'M',
            'mother_id_number' => '123456789',
            'mother_name' => 'Test mother',
            'mother_visit_type' => 'new',
            'mother_dob' => '2000-07-10',
            'consultation' => 'bf_support',
            'p_l' => 'L',
            'shelter_name' => 'mosaab_camp',
        ], $attributes));
    }

    private function sheet(): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'ic') . '.xlsx';

        file_put_contents($path, Excel::raw(
            new IndividualCounselingExport(IndividualCounseling::query()),
            \Maatwebsite\Excel\Excel::XLSX,
        ));

        $sheet = IOFactory::load($path)->getActiveSheet();

        @unlink($path);

        return $sheet;
    }

    /**
     * Column index (1-based) of a heading, so the assertions stay valid if the
     * layout ever shifts.
     */
    private function columnOf(Worksheet $sheet, string $heading): int
    {
        $width = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($column = 1; $column <= $width; $column++) {
            if ((string) $sheet->getCellByColumnAndRow($column, 1)->getValue() === $heading) {
                return $column;
            }
        }

        $this->fail("Heading [{$heading}] is not present in the export.");
    }

    public function test_a_blank_follow_up_visit_date_exports_as_an_empty_cell(): void
    {
        $record = $this->counseling();

        IndividualCounselingFollowup::create([
            'individual_counseling_id' => $record->id,
            'sort_order' => 1,
            'follow_up_visit_date' => null,
            'assess_and_analyze' => 'Assessed',
            'act' => 'Acted',
        ]);

        $sheet = $this->sheet();
        $date = $this->columnOf($sheet, __('fields.followup_date_n', ['n' => 1]));

        $value = $sheet->getCellByColumnAndRow($date, 2)->getValue();

        $this->assertNull($value, 'A blank follow-up date must leave the cell empty.');
        $this->assertNotSame('null', (string) $value);
        $this->assertNotSame('0000-00-00', (string) $value);
    }

    public function test_a_blank_assessment_or_action_exports_as_an_empty_cell(): void
    {
        $record = $this->counseling();

        IndividualCounselingFollowup::create([
            'individual_counseling_id' => $record->id,
            'sort_order' => 1,
            'follow_up_visit_date' => '2026-07-20',
            'assess_and_analyze' => null,
            'act' => null,
        ]);

        $sheet = $this->sheet();

        foreach (['followup_assess_n', 'followup_act_n'] as $key) {
            $column = $this->columnOf($sheet, __('fields.' . $key, ['n' => 1]));

            $this->assertNull(
                $sheet->getCellByColumnAndRow($column, 2)->getValue(),
                "A blank [{$key}] must leave the cell empty.",
            );
        }
    }

    public function test_a_populated_follow_up_session_still_exports_its_values(): void
    {
        $record = $this->counseling();

        IndividualCounselingFollowup::create([
            'individual_counseling_id' => $record->id,
            'sort_order' => 1,
            'follow_up_visit_date' => '2026-07-20',
            'assess_and_analyze' => 'Assessed',
            'act' => 'Acted',
        ]);

        $sheet = $this->sheet();

        $this->assertSame(
            '2026-07-20',
            $sheet->getCellByColumnAndRow($this->columnOf($sheet, __('fields.followup_date_n', ['n' => 1])), 2)->getValue(),
        );
        $this->assertSame(
            'Assessed',
            $sheet->getCellByColumnAndRow($this->columnOf($sheet, __('fields.followup_assess_n', ['n' => 1])), 2)->getValue(),
        );
        $this->assertSame(
            'Acted',
            $sheet->getCellByColumnAndRow($this->columnOf($sheet, __('fields.followup_act_n', ['n' => 1])), 2)->getValue(),
        );
    }

    /**
     * A record with fewer sessions than the widest record must pad with empty
     * cells rather than shortening its row: the column count is fixed by the
     * headings, so every row has to line up with them.
     */
    public function test_a_record_with_fewer_sessions_keeps_the_column_layout(): void
    {
        $wide = $this->counseling(['child_name' => 'Two sessions']);
        $narrow = $this->counseling(['child_name' => 'One session', 'mother_id_number' => '987654321']);

        foreach ([1, 2] as $order) {
            IndividualCounselingFollowup::create([
                'individual_counseling_id' => $wide->id,
                'sort_order' => $order,
                'follow_up_visit_date' => '2026-07-2' . $order,
                'assess_and_analyze' => 'A' . $order,
                'act' => 'B' . $order,
            ]);
        }

        IndividualCounselingFollowup::create([
            'individual_counseling_id' => $narrow->id,
            'sort_order' => 1,
            'follow_up_visit_date' => '2026-08-01',
            'assess_and_analyze' => 'Only',
            'act' => 'One',
        ]);

        $sheet = $this->sheet();
        $width = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        // Two session groups were produced, and both data rows span them all.
        $this->assertSame(
            $width,
            $this->columnOf($sheet, __('fields.followup_act_n', ['n' => 2])),
            'The last column must be the third column of the second session group.',
        );

        $secondGroupDate = $this->columnOf($sheet, __('fields.followup_date_n', ['n' => 2]));

        $rows = [];
        for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
            $rows[(string) $sheet->getCellByColumnAndRow($this->columnOf($sheet, __('fields.child_name')), $row)->getValue()] = $row;
        }

        $this->assertArrayHasKey('One session', $rows);
        $this->assertNull(
            $sheet->getCellByColumnAndRow($secondGroupDate, $rows['One session'])->getValue(),
            'The missing second session must be an empty cell, not a shortened row.',
        );
        $this->assertSame(
            '2026-07-22',
            $sheet->getCellByColumnAndRow($secondGroupDate, $rows['Two sessions'])->getValue(),
        );
    }

    /**
     * The columns that are not derived from the repeater must keep behaving
     * exactly as before this fix.
     */
    public function test_the_records_own_columns_are_unaffected(): void
    {
        $record = $this->counseling([
            'assess' => 'Own assessment',
            'analyze' => 'Own analysis',
            'act' => 'Own action',
        ]);

        IndividualCounselingFollowup::create([
            'individual_counseling_id' => $record->id,
            'sort_order' => 1,
            'follow_up_visit_date' => null,
            'assess_and_analyze' => null,
            'act' => null,
        ]);

        $sheet = $this->sheet();

        $this->assertSame('2026-07-10', $sheet->getCellByColumnAndRow($this->columnOf($sheet, __('fields.date')), 2)->getValue());
        $this->assertSame('Test child', $sheet->getCellByColumnAndRow($this->columnOf($sheet, __('fields.child_name')), 2)->getValue());
        $this->assertSame('Own assessment', $sheet->getCellByColumnAndRow($this->columnOf($sheet, __('fields.assess')), 2)->getValue());
        $this->assertSame('Own analysis', $sheet->getCellByColumnAndRow($this->columnOf($sheet, __('fields.analyze')), 2)->getValue());
        $this->assertSame('Own action', $sheet->getCellByColumnAndRow($this->columnOf($sheet, __('fields.act')), 2)->getValue());
    }

    /**
     * The base visit keeps Assess and Analyze in two columns of their own. The
     * merged "Assess and analyze" only ever belongs to a numbered session, so
     * the three headings have to be three distinct columns.
     */
    public function test_the_base_visit_assess_and_analyze_stay_separate_columns(): void
    {
        $record = $this->counseling(['assess' => 'Base assess', 'analyze' => 'Base analyze']);

        $record->followups()->create([
            'sort_order' => 1,
            'follow_up_visit_date' => '2026-07-20',
            'assess_and_analyze' => 'Merged for session one',
            'act' => 'Acted',
        ]);

        $sheet = $this->sheet();

        $assess = $this->columnOf($sheet, __('fields.assess'));
        $analyze = $this->columnOf($sheet, __('fields.analyze'));
        $merged = $this->columnOf($sheet, __('fields.followup_assess_n', ['n' => 1]));

        $this->assertCount(3, array_unique([$assess, $analyze, $merged]));

        $this->assertSame('Base assess', $sheet->getCellByColumnAndRow($assess, 2)->getValue());
        $this->assertSame('Base analyze', $sheet->getCellByColumnAndRow($analyze, 2)->getValue());
        $this->assertSame('Merged for session one', $sheet->getCellByColumnAndRow($merged, 2)->getValue());
    }

    /**
     * The record no longer carries a follow-up date of its own: every session
     * date lives in its numbered column group.
     */
    public function test_the_flat_follow_up_columns_are_gone_from_the_export(): void
    {
        $this->counseling();

        $headings = (new IndividualCounselingExport(IndividualCounseling::query()))->headings();

        $this->assertNotContains(__('fields.follow_up_visit_date'), $headings);
        $this->assertNotContains(__('fields.assess_and_analyze'), $headings);
    }

    /**
     * Six sessions is the ceiling, so six column groups is the widest the
     * export can ever get — a seventh session row cannot widen it further.
     */
    public function test_the_session_column_groups_stop_at_the_sixth(): void
    {
        $record = $this->counseling();

        foreach (range(1, IndividualCounseling::MAX_FOLLOWUP_SESSIONS + 1) as $order) {
            $record->followups()->create([
                'sort_order' => $order,
                'follow_up_visit_date' => '2026-07-01',
                'assess_and_analyze' => 'A' . $order,
                'act' => 'B' . $order,
            ]);
        }

        $sheet = $this->sheet();

        $this->assertSame(
            Coordinate::columnIndexFromString($sheet->getHighestColumn()),
            $this->columnOf($sheet, __('fields.followup_act_n', ['n' => 6])),
            'The last column must close the sixth session group.',
        );
    }

    /**
     * A data set with no sessions at all still carries one group, so the file
     * always has somewhere to type the first session.
     */
    public function test_a_data_set_without_sessions_still_carries_one_group(): void
    {
        $this->counseling();

        $headings = (new IndividualCounselingExport(IndividualCounseling::query()))->headings();

        $this->assertContains(__('fields.followup_date_n', ['n' => 1]), $headings);
        $this->assertNotContains(__('fields.followup_date_n', ['n' => 2]), $headings);
    }
}
