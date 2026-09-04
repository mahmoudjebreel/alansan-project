<?php

namespace Tests\Feature;

use App\Exports\ChildrenExport;
use App\Exports\FollowUpChildPdfExport;
use App\Exports\FollowUpChildrenExport;
use App\Exports\GroupSessionExport;
use App\Exports\IndividualCounselingExport;
use App\Exports\IndividualCounselingPdfExport;
use App\Exports\MotherToMotherExport;
use App\Exports\PdfExport;
use App\Exports\PregnantWomenExport;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one PDF layout, shared by all six modules.
 *
 * Every module prints the same way - module title, grey name bar, two-column
 * label/value grid - and only the two modules that keep repeated rows print a
 * sessions or visits table underneath. What differs between modules is the
 * data: each must show all of its own fields, not a common subset.
 */
class PdfRecordTemplateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A big report must still produce a PDF.
     *
     * mPDF parses the markup it is handed with PCRE, so a single WriteHTML()
     * of the whole document died with "The HTML code size is larger than
     * pcre.backtrack_limit" once the export grew past a few hundred records -
     * and the size at which it died was whatever the server's php.ini said.
     * The limit is lowered here rather than seeding hundreds of children, so
     * the regression is reproduced in a fraction of a second: every record
     * block stays well under it, the whole document does not.
     */
    public function test_a_report_larger_than_the_pcre_backtrack_limit_still_renders(): void
    {
        Child::factory()->count(12)->create();

        $original = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '20000');

        try {
            $response = PdfExport::download(
                new ChildrenExport(Child::query()),
                'children.pdf',
                __('fields.children'),
                'name',
            );

            ob_start();
            $response->sendContent();
            $content = ob_get_clean();
        } finally {
            ini_set('pcre.backtrack_limit', (string) $original);
        }

        $this->assertStringStartsWith('%PDF', $content);
        $this->assertGreaterThan(20000, strlen($content));
    }

    /**
     * One seeded record per module, with the export, the name attribute, the
     * name it should print, and the repeated section (or null) the module's
     * PDF is built from.
     */
    private function modules(): array
    {
        Child::factory()->create(['name' => 'طفل التقرير']);
        PregnantLactatingWoman::factory()->create(['full_name_ar' => 'حامل التقرير']);
        GroupSession::factory()->create(['full_name_ar' => 'جلسة جماعية']);
        MotherToMotherSession::factory()->create(['full_name_ar' => 'أم لأم']);

        $counseling = IndividualCounseling::factory()->create(['child_name' => 'طفل الإرشاد']);
        $counseling->followups()->create([
            'sort_order' => 1,
            'follow_up_visit_date' => '2026-08-01',
            'assess_and_analyze' => 'تقييم وتحليل',
            'act' => 'إجراء',
        ]);

        $followUpChild = FollowUpChild::factory()->create(['child_name' => 'طفل المتابعة']);
        $followUpChild->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-02', 'muac' => 121.5]);

        return [
            'children' => [new ChildrenExport(Child::query()), 'name', 'طفل التقرير', null],
            'pregnant_lactating_women' => [new PregnantWomenExport(PregnantLactatingWoman::query()), 'full_name_ar', 'حامل التقرير', null],
            'group_sessions' => [new GroupSessionExport(GroupSession::query()), 'full_name_ar', 'جلسة جماعية', null],
            'mother_to_mother_sessions' => [new MotherToMotherExport(MotherToMotherSession::query()), 'full_name_ar', 'أم لأم', null],
            'individual_counselings' => [
                new IndividualCounselingExport(IndividualCounseling::query()),
                'child_name', 'طفل الإرشاد', IndividualCounselingPdfExport::section(),
            ],
            'follow_up_children' => [
                new FollowUpChildrenExport(FollowUpChild::query()),
                'child_name', 'طفل المتابعة', FollowUpChildPdfExport::section(),
            ],
        ];
    }

    /**
     * The grey bar, the two-column grid and the shared styling are the same
     * markup for every module.
     */
    public function test_every_module_prints_through_the_same_layout(): void
    {
        foreach ($this->modules() as $key => [$export, $nameField, $name, $section]) {
            $html = PdfExport::render($export, __('fields.' . $key), $nameField, $section);

            $this->assertStringContainsString('<h1>' . __('fields.' . $key) . '</h1>', $html, $key);
            $this->assertStringContainsString('<h2>' . $name . '</h2>', $html, $key);
            $this->assertStringContainsString('.label { width: 26%; background: #fafafa;', $html, $key);
            $this->assertStringContainsString('h2 { font-size: 11px; margin: 0 0 6px; padding: 5px 6px; background: #e5e7eb; }', $html, $key);
            $this->assertStringContainsString('th, td { border: 1px solid #555;', $html, $key);
        }
    }

    /**
     * A module shows all of its own fields, not the handful modules share.
     */
    public function test_every_field_of_every_module_is_printed(): void
    {
        foreach ($this->modules() as $key => [$export, $nameField, $name, $section]) {
            $html = PdfExport::render($export, __('fields.' . $key), $nameField, $section);

            foreach ($export->fields() as $field) {
                $this->assertStringContainsString(
                    '<td class="label">' . __('fields.' . $field) . '</td>',
                    $html,
                    $key . ' is missing the field ' . $field,
                );
            }
        }
    }

    /**
     * Only the two modules that actually hold repeated rows get the numbered
     * table; the flat modules must not show an empty heading.
     */
    public function test_the_repeated_section_appears_only_where_the_module_has_one(): void
    {
        foreach ($this->modules() as $key => [$export, $nameField, $name, $section]) {
            $html = PdfExport::render($export, __('fields.' . $key), $nameField, $section);

            if ($section === null) {
                $this->assertStringNotContainsString('<h3>', $html, $key);
                $this->assertStringNotContainsString(__('fields.follow_up_sessions'), $html, $key);
                $this->assertStringNotContainsString('<th class="session-no">#</th>', $html, $key);

                continue;
            }

            $this->assertStringContainsString('<h3>' . $section['title'] . '</h3>', $html, $key);
            $this->assertStringContainsString('<th class="session-no">#</th>', $html, $key);
            $this->assertStringContainsString('<td class="session-no">1</td>', $html, $key);

            foreach ($section['columns'] as $column) {
                $this->assertStringContainsString($column, $html, $key);
            }
        }
    }

    public function test_the_follow_up_child_visits_are_numbered_rows(): void
    {
        $this->modules();

        $html = PdfExport::render(
            new FollowUpChildrenExport(FollowUpChild::query()),
            __('fields.follow_up_children'),
            'child_name',
            FollowUpChildPdfExport::section(),
        );

        $this->assertStringContainsString(__('fields.visits'), $html);
        $this->assertStringContainsString('2026-08-02', $html);
        $this->assertStringContainsString('121.5', $html);
        // The flat per-visit column groups belong to the spreadsheet only.
        $this->assertStringNotContainsString(__('fields.visit_muac_n', ['n' => 1]), $html);
    }

    /**
     * Every module still produces a real PDF after the unification.
     */
    public function test_every_module_downloads_a_pdf(): void
    {
        foreach ($this->modules() as $key => [$export, $nameField, $name, $section]) {
            $response = PdfExport::download($export, $key . '.pdf', __('fields.' . $key), $nameField, $section);

            ob_start();
            $response->sendContent();
            $content = ob_get_clean();

            $this->assertSame('application/pdf', $response->headers->get('Content-Type'), $key);
            $this->assertStringStartsWith('%PDF', $content, $key);
        }
    }
}
