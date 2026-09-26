<?php

namespace Tests\Feature;

use App\Exports\IndividualCounselingExport;
use App\Exports\IndividualCounselingPdfExport;
use App\Exports\PdfExport;
use App\Filament\Resources\IndividualCounselingResource;
use App\Filament\Resources\IndividualCounselingResource\Pages\EditIndividualCounseling;
use App\Filament\Resources\IndividualCounselingResource\Pages\ViewIndividualCounseling;
use App\Models\IndividualCounseling;
use App\Models\User;
use App\Support\RichText;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * The Individual Counseling assessment notes are rich text.
 *
 * The form stores HTML; nothing else in the module may show it. A note saved
 * as plain text before the change keeps its line breaks in the editor, on the
 * view page and in both exports, and a note with the editor's formatting
 * comes out of the spreadsheet and the PDF as readable text.
 */
class IndividualCounselingRichTextTest extends TestCase
{
    use RefreshDatabase;

    private const PLAIN = "السطر الأول\nالسطر الثاني <115 ملم";

    private const PLAIN_AS_HTML = '<p>السطر الأول</p><p>السطر الثاني &lt;115 ملم</p>';

    private const HTML = '<p>ملاحظة <strong>مهمة</strong></p><ul><li><p>نقطة أولى</p></li><li><p>نقطة ثانية</p></li></ul>';

    private const HTML_AS_PLAIN = "ملاحظة مهمة\n- نقطة أولى\n- نقطة ثانية";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
    }

    private function counseling(array $attributes = []): IndividualCounseling
    {
        return IndividualCounseling::create(array_merge([
            'date' => '2026-08-26',
            'health_educator' => IndividualCounselingResource::HEALTH_EDUCATOR,
            'shelter_name' => 'mahabba',
            'child_name' => 'طفل تجريبي',
            'child_visit_type' => 'new',
            'child_dob' => '2025-02-26',
            'gender' => 'F',
            'p_l' => 'P+L',
            'mother_name' => 'أم تجريبية',
            'mother_id_number' => '123456789',
            'mother_visit_type' => 'new',
            'mother_dob' => '1996-02-26',
            'mobile_number' => '0599123456',
            'muac' => 118,
            'child_age_lactated' => '6_23_months',
            'feeding_type' => 'Exclusive Breastfeeding',
            'iycf_form_filled' => 1,
            'status' => 'under_follow_up',
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

    private function cell(Worksheet $sheet, string $heading): mixed
    {
        $width = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($column = 1; $column <= $width; $column++) {
            if ((string) $sheet->getCellByColumnAndRow($column, 1)->getValue() === $heading) {
                return $sheet->getCellByColumnAndRow($column, 2)->getValue();
            }
        }

        $this->fail("Heading [{$heading}] is not present in the export.");
    }

    private function pdfHtml(): string
    {
        return PdfExport::render(
            new IndividualCounselingExport(IndividualCounseling::query()),
            __('fields.individual_counselings'),
            'child_name',
            IndividualCounselingPdfExport::section(),
        );
    }

    /** Every rich editor in the form, keyed by field name. */
    private function richEditors(): array
    {
        $editors = [];

        $walk = function (iterable $components) use (&$walk, &$editors): void {
            foreach ($components as $component) {
                if ($component instanceof RichEditor) {
                    $editors[$component->getName()] = $component;
                }

                $walk($component->getDefaultChildComponents());
            }
        };

        $walk(IndividualCounselingResource::form(Schema::make())->getComponents());

        return $editors;
    }

    // -----------------------------------------------------------------
    // The conversions
    // -----------------------------------------------------------------

    public function test_plain_text_becomes_one_paragraph_per_line_and_html_is_left_alone(): void
    {
        $this->assertSame(self::PLAIN_AS_HTML, RichText::toHtml(self::PLAIN));
        $this->assertSame(self::HTML, RichText::toHtml(self::HTML));
        $this->assertNull(RichText::toHtml(null));
        $this->assertSame('', RichText::toHtml(''));
    }

    public function test_html_becomes_text_with_its_lines_and_plain_text_is_left_alone(): void
    {
        $this->assertSame(self::HTML_AS_PLAIN, RichText::toPlain(self::HTML));
        $this->assertSame(self::PLAIN, RichText::toPlain(self::PLAIN), 'A "<" in a plain note is not a tag.');
        $this->assertSame("أ\nب", RichText::toPlain('<p>أ<br>ب</p>'));
        $this->assertSame('أ&ب', RichText::toPlain('<p>أ&amp;ب</p>'));
        $this->assertNull(RichText::toPlain(null));
    }

    // -----------------------------------------------------------------
    // The form
    // -----------------------------------------------------------------

    public function test_the_five_note_fields_are_rich_editors_without_file_attachments(): void
    {
        $editors = $this->richEditors();

        $this->assertSame(
            ['assess', 'analyze', 'act', 'assess_and_analyze'],
            array_keys($editors),
            'The repeater\'s "act" shares its name with the base visit\'s, so four names cover five fields.',
        );

        foreach ($editors as $name => $editor) {
            $this->assertFalse($editor->hasFileAttachments(), "[{$name}] must not accept file attachments.");
        }
    }

    public function test_a_note_saved_as_plain_text_keeps_its_line_breaks_through_an_edit(): void
    {
        $this->actingAsAdmin();

        $record = $this->counseling(['assess' => self::PLAIN, 'analyze' => self::PLAIN, 'act' => self::PLAIN]);
        $record->followups()->create([
            'sort_order' => 0,
            'follow_up_visit_date' => '2026-09-01',
            'assess_and_analyze' => self::PLAIN,
            'act' => self::PLAIN,
        ]);

        // Open and save without touching anything: what the editor was handed
        // is what gets written back.
        Livewire::test(EditIndividualCounseling::class, ['record' => $record->getRouteKey()])
            ->assertSuccessful()
            ->call('save')
            ->assertHasNoFormErrors();

        $record->refresh();

        foreach (['assess', 'analyze', 'act'] as $field) {
            $this->assertSame(self::PLAIN_AS_HTML, $record->{$field}, "[{$field}] lost its line breaks.");
        }

        $session = $record->followups->first();
        $this->assertSame(self::PLAIN_AS_HTML, $session->assess_and_analyze);
        $this->assertSame(self::PLAIN_AS_HTML, $session->act);
    }

    public function test_an_empty_editor_is_a_blank_note(): void
    {
        $this->assertTrue(RichText::isBlank(null));
        $this->assertTrue(RichText::isBlank(''));
        $this->assertTrue(RichText::isBlank('<p></p>'));
        $this->assertTrue(RichText::isBlank("<p>\u{A0}</p>"));
        $this->assertFalse(RichText::isBlank('<p>أ</p>'));
        $this->assertFalse(RichText::isBlank('أ'));
    }

    public function test_a_formatted_note_survives_an_edit_unchanged(): void
    {
        $this->actingAsAdmin();

        $record = $this->counseling(['assess' => self::HTML]);
        $record->followups()->create([
            'sort_order' => 0,
            'follow_up_visit_date' => '2026-09-01',
            'assess_and_analyze' => self::HTML,
            'act' => null,
        ]);

        Livewire::test(EditIndividualCounseling::class, ['record' => $record->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $record->refresh();

        $this->assertSame(self::HTML, $record->assess);
        $this->assertSame(self::HTML, $record->followups->first()->assess_and_analyze);
        $this->assertNull($record->followups->first()->act);
    }

    // -----------------------------------------------------------------
    // The view page
    // -----------------------------------------------------------------

    public function test_the_view_page_renders_formatting_and_never_shows_tags(): void
    {
        $this->actingAsAdmin();

        $record = $this->counseling(['assess' => self::HTML, 'analyze' => self::PLAIN]);
        $record->followups()->create([
            'sort_order' => 0,
            'follow_up_visit_date' => '2026-09-01',
            'assess_and_analyze' => self::HTML,
            'act' => self::PLAIN,
        ]);

        $page = Livewire::test(ViewIndividualCounseling::class, ['record' => $record->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeHtml('<strong>مهمة</strong>')
            ->assertSeeHtml('<li><p>نقطة أولى</p></li>')
            ->assertSeeHtml('<p>السطر الأول</p><p>السطر الثاني &lt;115 ملم</p>');

        $this->assertStringNotContainsString('&lt;strong&gt;', $page->html());
        $this->assertStringNotContainsString('&lt;p&gt;', $page->html());
    }

    // -----------------------------------------------------------------
    // The exports
    // -----------------------------------------------------------------

    public function test_the_spreadsheet_prints_the_notes_as_text(): void
    {
        $record = $this->counseling(['assess' => self::HTML, 'analyze' => self::PLAIN, 'act' => null]);
        $record->followups()->create([
            'sort_order' => 0,
            'follow_up_visit_date' => '2026-09-01',
            'assess_and_analyze' => self::HTML,
            'act' => self::PLAIN,
        ]);

        $sheet = $this->sheet();

        $this->assertSame(self::HTML_AS_PLAIN, $this->cell($sheet, __('fields.assess')));
        $this->assertSame(self::PLAIN, $this->cell($sheet, __('fields.analyze')));
        $this->assertNull($this->cell($sheet, __('fields.act')));
        $this->assertSame(self::HTML_AS_PLAIN, $this->cell($sheet, __('fields.followup_assess_n', ['n' => 1])));
        $this->assertSame(self::PLAIN, $this->cell($sheet, __('fields.followup_act_n', ['n' => 1])));
    }

    public function test_the_pdf_prints_the_notes_as_text(): void
    {
        $record = $this->counseling(['assess' => self::HTML, 'analyze' => self::PLAIN]);
        $record->followups()->create([
            'sort_order' => 0,
            'follow_up_visit_date' => '2026-09-01',
            'assess_and_analyze' => self::HTML,
            'act' => self::PLAIN,
        ]);

        $html = $this->pdfHtml();

        $this->assertStringContainsString('ملاحظة مهمة', $html);
        $this->assertStringContainsString('- نقطة أولى', $html);
        $this->assertStringContainsString('السطر الثاني &lt;115 ملم', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('&lt;strong&gt;', $html);
        $this->assertStringNotContainsString('&lt;p&gt;', $html);
    }
}
