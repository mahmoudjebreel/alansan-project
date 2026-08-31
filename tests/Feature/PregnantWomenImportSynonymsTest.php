<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\Import\PregnantWomanImportSynonyms;
use App\Support\ImportSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Bilingual reading of the Pregnant / Lactating Women import.
 *
 * The field workbooks for this module arrive written in Arabic from one team
 * and in English from the next, so both spellings of every Select column have
 * to land on the same stored value. What must not happen is the other half of
 * that: a real misspelling being quietly read as the option it looks closest
 * to. Both halves are checked here.
 */
class PregnantWomenImportSynonymsTest extends TestCase
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
        return ImportDefinition::get('pregnant');
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
            __('fields.mother_id') => '123456789',
            __('fields.full_name_ar') => 'أم الاستيراد',
            __('fields.phone_number') => '0591234567',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => '2026-08-20',
            __('fields.date_of_birth') => '1996-01-01',
            __('fields.governorate') => 'gaza',
            __('fields.municipality') => 'gaza',
            __('fields.muac_mm') => '230',
            __('fields.status_type') => 'حامل',
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
     */
    private function import(array $rows): array
    {
        $export = new class(array_merge([$this->headings()], $rows)) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'plw-import-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            $this->definition(),
            \Storage::disk('local')->path($name),
        );
    }

    // -----------------------------------------------------------------
    // Both languages accepted
    // -----------------------------------------------------------------

    public function test_an_arabic_sheet_still_imports(): void
    {
        $result = $this->import([$this->row([
            __('fields.status_type') => 'حامل',
            __('fields.type_of_site') => 'مخيم السلام',
            __('fields.neighbourhood') => 'الشاطئ',
            __('fields.status') => 'متزوجة',
            __('fields.is_displaced') => 'نعم',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $woman = PregnantLactatingWoman::first();

        $this->assertSame('pregnant', $woman->status_type);
        $this->assertSame('El Salam Camp', $woman->type_of_site);
        $this->assertSame('El Shatee', $woman->neighbourhood);
        $this->assertSame('متزوجة', $woman->status);
        $this->assertTrue((bool) $woman->is_displaced);
    }

    public function test_an_english_sheet_imports_onto_the_same_stored_values(): void
    {
        $result = $this->import([$this->row([
            __('fields.status_type') => 'Breastfeeding',
            __('fields.type_of_site') => 'El Salam Camp',
            __('fields.neighbourhood') => 'Tal Al Hawa',
            __('fields.status') => 'Married',
            __('fields.is_displaced') => 'Yes',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $woman = PregnantLactatingWoman::first();

        $this->assertSame('lactating', $woman->status_type);
        $this->assertSame('El Salam Camp', $woman->type_of_site);
        $this->assertSame('Tal EalHawa', $woman->neighbourhood);
        $this->assertSame('متزوجة', $woman->status);
        $this->assertTrue((bool) $woman->is_displaced);
    }

    public function test_the_two_languages_may_be_mixed_within_one_file(): void
    {
        $result = $this->import([
            $this->row([
                __('fields.mother_id') => '111111111',
                __('fields.status_type') => 'Pregnant',
                __('fields.type_of_site') => 'مخيم مصعب',
            ]),
            $this->row([
                __('fields.mother_id') => '222222222',
                __('fields.status_type') => 'مرضع',
                __('fields.type_of_site') => 'Mahabba',
            ]),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);

        $this->assertSame('pregnant', PregnantLactatingWoman::where('mother_id', '111111111')->value('status_type'));
        $this->assertSame('Mossab Camp', PregnantLactatingWoman::where('mother_id', '111111111')->value('type_of_site'));
        $this->assertSame('lactating', PregnantLactatingWoman::where('mother_id', '222222222')->value('status_type'));
        $this->assertSame('Mahabba Camp', PregnantLactatingWoman::where('mother_id', '222222222')->value('type_of_site'));
    }

    public function test_surrounding_whitespace_and_letter_case_do_not_matter(): void
    {
        $result = $this->import([$this->row([
            __('fields.status_type') => '  PREGNANT  ',
            __('fields.type_of_site') => "\u{00A0}el salam camp ",
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $woman = PregnantLactatingWoman::first();

        $this->assertSame('pregnant', $woman->status_type);
        $this->assertSame('El Salam Camp', $woman->type_of_site);
    }

    // -----------------------------------------------------------------
    // Misspellings stay refused
    // -----------------------------------------------------------------

    public function test_an_arabic_misspelling_is_refused_and_never_guessed_at(): void
    {
        $result = $this->import([$this->row([
            __('fields.status_type') => 'حاملة',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('حاملة', $result['errors'][0]);
        $this->assertStringContainsString(__('fields.status_type'), $result['errors'][0]);
        // The message has to name what the file may say instead.
        $this->assertStringContainsString('Breastfeeding', $result['errors'][0]);

        $this->assertSame(0, PregnantLactatingWoman::count());
    }

    public function test_an_english_misspelling_is_refused_and_never_guessed_at(): void
    {
        $result = $this->import([$this->row([
            __('fields.status_type') => 'Pregnent',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Pregnent', $result['errors'][0]);

        $this->assertSame(0, PregnantLactatingWoman::count());
    }

    public function test_a_misspelled_site_is_refused_rather_than_matched_to_the_nearest_camp(): void
    {
        $result = $this->import([$this->row([
            __('fields.type_of_site') => 'El Salm Camp',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('El Salam Camp', $result['errors'][0]);

        $this->assertSame(0, PregnantLactatingWoman::count());
    }

    public function test_the_rejection_names_the_row_it_came_from(): void
    {
        $result = $this->import([
            $this->row([__('fields.mother_id') => '111111111']),
            $this->row([
                __('fields.mother_id') => '222222222',
                __('fields.status_type') => 'حاملة',
            ]),
        ]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(__('fields.import_row_error', ['row' => 3, 'message' => '']), $result['errors'][0]);
    }

    // -----------------------------------------------------------------
    // Nothing else moved
    // -----------------------------------------------------------------

    public function test_a_blank_optional_select_is_still_simply_blank(): void
    {
        $result = $this->import([$this->row([
            __('fields.type_of_site') => null,
            __('fields.status') => '',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $woman = PregnantLactatingWoman::first();

        $this->assertNull($woman->type_of_site);
        $this->assertNull($woman->status);
    }

    public function test_a_missing_required_column_value_is_still_reported_as_required(): void
    {
        $result = $this->import([$this->row([
            __('fields.status_type') => null,
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(
            __('fields.import_required', ['field' => __('fields.status_type')]),
            $result['errors'][0],
        );
    }

    public function test_every_spelling_the_module_itself_writes_is_in_the_map(): void
    {
        // The map has to be a superset of what this module already produces:
        // the stored value, the label the form shows and both translations of
        // it. Adding an option to the form without adding it here is exactly
        // the drift this guards against — a file exported today would come
        // back rejected tomorrow.
        $definition = $this->definition();
        $schema = new ImportSchema($definition);

        $checked = 0;

        foreach ($schema->selectOptions() as $field => $options) {
            if (! in_array($field, $definition->fields(), true)) {
                continue;
            }

            $spellings = [];

            foreach ($options as $stored => $label) {
                $spellings[] = (string) $stored;
                $spellings[] = (string) $label;

                foreach (['ar', 'en'] as $locale) {
                    $translated = trans('fields.' . $stored, [], $locale);

                    if ($translated !== 'fields.' . $stored) {
                        $spellings[] = $translated;
                    }
                }
            }

            foreach (array_unique($spellings) as $spelling) {
                if (trim($spelling) === '') {
                    continue;
                }

                $checked++;

                $this->assertTrue(
                    PregnantWomanImportSynonyms::normalise($field, $spelling)['ok'],
                    "[{$field}] offers [{$spelling}], which the synonym map does not accept.",
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'No Select options were read off the form.');
    }

    public function test_other_modules_keep_reading_their_files_unchanged(): void
    {
        // The normalisation hook is overridden for this module only: the shared
        // engine's default has to leave every other module's cells alone.
        $default = new \ReflectionMethod(\App\Imports\AbstractTableImport::class, 'normaliseValue');
        $default->setAccessible(true);

        foreach (['children', 'group_sessions', 'mother_to_mother', 'individual_counseling', 'follow_up_children'] as $key) {
            $importer = new \ReflectionClass(\App\Services\ExcelImportService::class);
            $resolve = $importer->getMethod('importerFor');
            $resolve->setAccessible(true);

            $class = $resolve->invoke(new \App\Services\ExcelImportService(), ImportDefinition::get($key));

            $this->assertSame(
                \App\Imports\AbstractTableImport::class,
                (new \ReflectionMethod($class, 'normaliseValue'))->getDeclaringClass()->getName(),
                "[{$class}] must keep the shared engine's untouched normalisation.",
            );
        }
    }
}
