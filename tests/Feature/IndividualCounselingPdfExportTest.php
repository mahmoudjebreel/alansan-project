<?php

namespace Tests\Feature;

use App\Exports\IndividualCounselingExport;
use App\Exports\IndividualCounselingPdfExport;
use App\Exports\PdfExport;
use App\Models\IndividualCounseling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PDF report for the Individual Counseling module.
 *
 * The report is shaped like the record, not like the spreadsheet: each record
 * is one block, and its follow-up sessions are numbered rows underneath rather
 * than eighteen extra columns nobody could read.
 */
class IndividualCounselingPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private function counseling(array $attributes = []): IndividualCounseling
    {
        return IndividualCounseling::create(array_merge([
            'date' => '2026-07-10',
            'child_name' => 'طفل التقرير',
            'child_visit_type' => 'follow_up',
            'gender' => 'M',
            'p_l' => 'P+L',
            'shelter_name' => 'al_helou',
            'mother_id_number' => '123456789',
            'mother_name' => 'أم التقرير',
        ], $attributes));
    }

    /**
     * Render the report's HTML, which is what the mPDF step is handed. This is
     * the same call download() makes, through the shared layout.
     */
    private function html(): string
    {
        return PdfExport::render(
            new IndividualCounselingExport(IndividualCounseling::query()),
            __('fields.individual_counselings'),
            'child_name',
            IndividualCounselingPdfExport::section(),
        );
    }

    public function test_each_session_is_listed_under_its_record(): void
    {
        $record = $this->counseling();

        foreach ([1, 2] as $order) {
            $record->followups()->create([
                'sort_order' => $order,
                'follow_up_visit_date' => '2026-08-0' . $order,
                'assess_and_analyze' => 'تقييم وتحليل ' . $order,
                'act' => 'إجراء ' . $order,
            ]);
        }

        $html = $this->html();

        $this->assertStringContainsString('طفل التقرير', $html);
        $this->assertStringContainsString(__('fields.follow_up_sessions'), $html);

        foreach ([1, 2] as $order) {
            $this->assertStringContainsString('2026-08-0' . $order, $html);
            $this->assertStringContainsString('تقييم وتحليل ' . $order, $html);
            $this->assertStringContainsString('إجراء ' . $order, $html);
        }
    }

    /**
     * The base visit's own Assess and Analyze are two labelled fields of their
     * own, distinct from a session's merged "Assess and analyze".
     */
    public function test_the_base_visit_assess_and_analyze_appear_as_separate_fields(): void
    {
        $this->counseling(['assess' => 'تقييم أساسي', 'analyze' => 'تحليل أساسي']);

        $html = $this->html();

        $this->assertStringContainsString(__('fields.assess'), $html);
        $this->assertStringContainsString(__('fields.analyze'), $html);
        $this->assertStringContainsString('تقييم أساسي', $html);
        $this->assertStringContainsString('تحليل أساسي', $html);
    }

    public function test_a_blank_session_field_prints_as_an_empty_cell(): void
    {
        $record = $this->counseling();

        $record->followups()->create([
            'sort_order' => 1,
            'follow_up_visit_date' => null,
            'assess_and_analyze' => null,
            'act' => null,
        ]);

        $html = $this->html();

        $this->assertStringNotContainsString('null', $html);
        $this->assertStringNotContainsString('0000-00-00', $html);
    }

    public function test_a_record_without_sessions_says_so_instead_of_printing_nothing(): void
    {
        $this->counseling();

        $this->assertStringContainsString(__('fields.no_follow_up_sessions'), $this->html());
    }

    /**
     * The report must not fall back to the shared flat table, whose session
     * columns are what made it unreadable.
     */
    public function test_the_report_does_not_use_the_flat_session_columns(): void
    {
        $record = $this->counseling();
        $record->followups()->create(['sort_order' => 1, 'follow_up_visit_date' => '2026-08-01']);

        $html = $this->html();

        $this->assertStringNotContainsString(__('fields.followup_date_n', ['n' => 1]), $html);
        $this->assertStringNotContainsString(__('fields.visit_muac_n', ['n' => 1]), $html);
    }

    public function test_the_download_produces_a_pdf(): void
    {
        $record = $this->counseling();
        $record->followups()->create([
            'sort_order' => 1,
            'follow_up_visit_date' => '2026-08-01',
            'assess_and_analyze' => 'تقييم',
            'act' => 'إجراء',
        ]);

        $response = IndividualCounselingPdfExport::download(
            IndividualCounseling::query(),
            'individual-counselings.pdf',
            __('fields.individual_counselings'),
        );

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $content);
    }
}
