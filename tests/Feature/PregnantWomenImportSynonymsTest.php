<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\Import\PregnantWomanImportDates;
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

    public function test_the_combined_status_imports_in_both_languages(): void
    {
        $result = $this->import([
            $this->row([__('fields.mother_id') => '111111111', __('fields.status_type') => 'حامل + مرضع']),
            $this->row([__('fields.mother_id') => '222222222', __('fields.status_type') => 'Pregnant + Breastfeeding']),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(
            ['pregnant_lactating', 'pregnant_lactating'],
            PregnantLactatingWoman::query()->orderBy('mother_id')->pluck('status_type')->all(),
        );
    }

    /**
     * The field workbooks are filled in with the one-letter codes far more
     * often than with the words, so a file full of P / L / P/L has to import
     * rather than fail on every single row.
     */
    public function test_the_one_letter_codes_the_field_workbooks_use_are_accepted(): void
    {
        $result = $this->import([
            $this->row([__('fields.mother_id') => '111111111', __('fields.status_type') => 'P']),
            $this->row([__('fields.mother_id') => '222222222', __('fields.status_type') => 'L']),
            $this->row([__('fields.mother_id') => '333333333', __('fields.status_type') => 'P/L']),
            $this->row([__('fields.mother_id') => '444444444', __('fields.status_type') => 'PL']),
            // Lower case, and padded, exactly as a hand-typed cell arrives.
            $this->row([__('fields.mother_id') => '555555555', __('fields.status_type') => ' p ']),
            $this->row([__('fields.mother_id') => '666666666', __('fields.status_type') => 'l']),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(6, $result['imported']);

        $this->assertSame(
            [
                '111111111' => 'pregnant',
                '222222222' => 'lactating',
                '333333333' => 'pregnant_lactating',
                '444444444' => 'pregnant_lactating',
                '555555555' => 'pregnant',
                '666666666' => 'lactating',
            ],
            PregnantLactatingWoman::query()->orderBy('mother_id')->pluck('status_type', 'mother_id')->all(),
        );
    }

    public function test_the_reversed_code_means_the_same_as_the_forward_one(): void
    {
        $result = $this->import([
            $this->row([__('fields.mother_id') => '111111111', __('fields.status_type') => 'P/L']),
            $this->row([__('fields.mother_id') => '222222222', __('fields.status_type') => 'L/P']),
            $this->row([__('fields.mother_id') => '333333333', __('fields.status_type') => 'l/p']),
            $this->row([__('fields.mother_id') => '444444444', __('fields.status_type') => 'L/p']),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(4, $result['imported']);
        $this->assertSame(
            ['pregnant_lactating', 'pregnant_lactating', 'pregnant_lactating', 'pregnant_lactating'],
            PregnantLactatingWoman::query()->orderBy('mother_id')->pluck('status_type')->all(),
        );
    }

    public function test_the_abandoned_marital_status_is_accepted_in_both_languages(): void
    {
        $result = $this->import([
            $this->row([__('fields.mother_id') => '111111111', __('fields.status') => 'مهجورة']),
            $this->row([__('fields.mother_id') => '222222222', __('fields.status') => 'Abandoned']),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(
            ['مهجورة', 'مهجورة'],
            PregnantLactatingWoman::query()->orderBy('mother_id')->pluck('status')->all(),
        );
    }

    public function test_the_pending_marital_status_is_accepted_with_and_without_the_shadda(): void
    {
        $result = $this->import([
            // The spelling the workbooks actually hold...
            $this->row([__('fields.mother_id') => '111111111', __('fields.status') => 'معلقة']),
            // ...the same word written with the shadda...
            $this->row([__('fields.mother_id') => '222222222', __('fields.status') => 'معلّقة']),
            // ...and the English label.
            $this->row([__('fields.mother_id') => '333333333', __('fields.status') => 'Pending']),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $result['imported']);
        $this->assertSame(
            ['معلقة', 'معلقة', 'معلقة'],
            PregnantLactatingWoman::query()->orderBy('mother_id')->pluck('status')->all(),
        );
    }

    public function test_the_form_offers_the_two_added_statuses_too(): void
    {
        // The Select is what both the manual form and the importer validate
        // against, so an option has to be on it, not only in the map.
        $options = \App\Filament\Resources\PregnantLactatingWomanResource::maritalStatusOptions();

        $this->assertArrayHasKey('مهجورة', $options);
        $this->assertArrayHasKey('معلقة', $options);

        // The six that were already there are untouched.
        foreach (['متزوجة', 'أرملة', 'مطلقة', 'منفصلة', 'الزوج مفقود'] as $existing) {
            $this->assertArrayHasKey($existing, $options);
        }
    }

    public function test_a_letter_that_is_not_a_known_code_is_still_refused(): void
    {
        // Adding P and L must not turn the column into "any short string goes".
        foreach (['X', 'Preg', 'B'] as $value) {
            $result = $this->import([$this->row([__('fields.status_type') => $value])]);

            $this->assertSame(0, $result['imported'], "[{$value}] must not be accepted.");
            $this->assertStringContainsString($value, $result['errors'][0]);
        }
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

    // -----------------------------------------------------------------
    // Newborn date of birth, typed as text
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function readableNewbornDates(): array
    {
        return [
            'day-first, four digit year' => ['24/11/2025', '2025-11-24'],
            'day-first, two digit year' => ['24/11/25', '2025-11-24'],
            'no leading zeros' => ['4/1/2025', '2025-01-04'],
            'mistyped backslashes' => ['04\\01\\2026', '2026-01-04'],
            'dashes' => ['24-11-2025', '2025-11-24'],
            'dots' => ['24.11.2025', '2025-11-24'],
            'iso is still iso' => ['2025-11-24', '2025-11-24'],
            'iso with slashes' => ['2025/11/24', '2025-11-24'],
            // The one that used to import silently as the 12th of May.
            'ambiguous cell is read day-first' => ['05/12/2025', '2025-12-05'],
            'a real leap day' => ['29/2/2024', '2024-02-29'],
            // A named month settles which component is the month on its own,
            // so both orderings are safe.
            'month named first' => ['Aug/30/2026', '2026-08-30'],
            'month named in full' => ['August/30/2026', '2026-08-30'],
            'month named, upper case' => ['AUG/30/2026', '2026-08-30'],
            'month named with dashes' => ['30-Aug-2026', '2026-08-30'],
            'month named with spaces' => ['30 Aug 2026', '2026-08-30'],
            'month named, day after' => ['Aug 30 2026', '2026-08-30'],
            'month named with a comma' => ['Aug 30, 2026', '2026-08-30'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readableNewbornDates')]
    public function test_a_newborn_date_typed_as_text_is_read_day_first(string $cell, string $expected): void
    {
        $result = $this->import([$this->row([
            __('fields.status_type') => 'L',
            __('fields.newborn_dob') => $cell,
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame($expected, PregnantLactatingWoman::first()->newborn_dob->format('Y-m-d'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadableNewbornDates(): array
    {
        // Every one of these is a real cell, byte for byte, from the workbook
        // whose rows were being refused.
        return [
            'april has no 31st' => ['31/4/2025'],
            'there is no 21st month' => ['21/21/26'],
            'a separator was missed' => ['10/1024'],
            'another missed separator' => ['19/1225'],
            'the disability column leaked in' => ['إصابة وجه و كفين'],
            'a stray letter' => ['و'],
            'a note, not a date' => ['حامل بتوأم'],
            'another note' => ['طفل إعاقة بصرية  و الزوج اسير محرر'],
            'a leap day that was not one' => ['29/2/2025'],
        ];
    }

    /**
     * The column is optional, so an unreadable cell costs that one cell — not
     * the row, and not the file. What must never happen is the cell being
     * guessed at: none of these may come out as a stored date.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableNewbornDates')]
    public function test_an_unreadable_newborn_date_is_dropped_rather_than_failing_its_row(string $cell): void
    {
        $result = $this->import([$this->row([
            __('fields.status_type') => 'L',
            __('fields.newborn_dob') => $cell,
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertNull(
            PregnantLactatingWoman::first()->newborn_dob,
            "[{$cell}] must not be read as a date.",
        );
    }

    public function test_a_dropped_newborn_date_is_logged_with_the_value_it_held(): void
    {
        // Dropping is a judgement about an optional column, so it must not be
        // silent: the workbook still has to be correctable afterwards.
        \Illuminate\Support\Facades\Log::spy();

        $this->import([$this->row([
            __('fields.status_type') => 'L',
            __('fields.newborn_dob') => '31/4/2025',
        ])]);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context['field'] === 'newborn_dob'
                && $context['value'] === '31/4/2025')
            ->once();
    }

    public function test_an_unreadable_date_in_a_required_column_still_fails_the_row(): void
    {
        // The drop is confined to the optional column it was decided for.
        $result = $this->import([$this->row([
            __('fields.date_of_reporting') => 'إصابة وجه و كفين',
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(
            __('fields.import_invalid_date', ['field' => __('fields.date_of_reporting')]),
            $result['errors'][0],
        );
    }

    public function test_a_blank_reporting_date_is_still_required(): void
    {
        // Reading the column more flexibly must not make it optional.
        $result = $this->import([$this->row([
            __('fields.date_of_reporting') => null,
        ])]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(
            __('fields.import_required', ['field' => __('fields.date_of_reporting')]),
            $result['errors'][0],
        );
    }

    public function test_a_blank_newborn_date_was_never_the_problem(): void
    {
        // The column is optional, and a blank cell always passed. Confirmed so
        // the fix is not credited with something that already worked.
        $result = $this->import([$this->row([
            __('fields.status_type') => 'L',
            __('fields.newborn_dob') => null,
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertNull(PregnantLactatingWoman::first()->newborn_dob);
    }

    /**
     * The whole point of doing every date column at once: one batch of
     * hand-typed rows must not fail on one column after another as each is
     * discovered in turn.
     */
    public function test_every_date_column_of_this_module_is_read_the_same_way(): void
    {
        foreach (['date_of_reporting', 'date_of_birth', 'newborn_dob'] as $field) {
            $this->assertTrue(
                PregnantWomanImportDates::handles($field),
                "[{$field}] is a date column of this module and must be read like the others.",
            );
        }

        // Losing a cell instead of the row stays confined to the optional one.
        $this->assertTrue(PregnantWomanImportDates::mayDrop('newborn_dob'));
        $this->assertFalse(PregnantWomanImportDates::mayDrop('date_of_reporting'));
        $this->assertFalse(PregnantWomanImportDates::mayDrop('date_of_birth'));
    }

    public function test_a_hand_typed_cell_is_read_day_first_in_every_date_column(): void
    {
        // '7/12/95' is a real cell from the workbook's Date of Birth column. It
        // never failed — it imported as the 12th of July, which is not what the
        // file says. 'Aug/30/2026' is the batch that did fail, fifty rows of it.
        $result = $this->import([$this->row([
            __('fields.date_of_reporting') => 'Aug/30/2026',
            __('fields.date_of_birth') => '7/12/95',
            __('fields.status_type') => 'L',
            __('fields.newborn_dob') => '24/11/25',
        ])]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $woman = PregnantLactatingWoman::first();

        $this->assertSame('2026-08-30', $woman->date_of_reporting->format('Y-m-d'));
        $this->assertSame('1995-12-07', $woman->date_of_birth->format('Y-m-d'));
        $this->assertSame('2025-11-24', $woman->newborn_dob->format('Y-m-d'));
    }

    public function test_an_impossible_date_is_refused_in_the_required_columns_too(): void
    {
        foreach (['date_of_reporting', 'date_of_birth'] as $field) {
            $result = $this->import([$this->row([__('fields.' . $field) => '31/4/2025'])]);

            $this->assertSame(0, $result['imported'], "[{$field}] must refuse the 31st of April.");
            $this->assertStringContainsString(
                __('fields.import_invalid_date', ['field' => __('fields.' . $field)]),
                $result['errors'][0],
            );
        }
    }

    public function test_no_two_spellings_in_one_map_collapse_onto_different_values(): void
    {
        // Comparison folds case and whitespace, so two spellings that differ
        // only in those would silently overwrite each other and one option
        // would start importing as another. Short codes like P and L make that
        // easy to do by accident; this is the guard against it.
        $values = new \ReflectionClass(PregnantWomanImportSynonyms::class);
        $fields = $values->getConstant('FIELDS');

        $key = new \ReflectionMethod(PregnantWomanImportSynonyms::class, 'key');
        $key->setAccessible(true);

        foreach ($fields as $field => $map) {
            $seen = [];

            foreach ($map['values'] as $spelling => $stored) {
                $folded = $key->invoke(null, (string) $spelling);

                $this->assertSame(
                    $seen[$folded] ?? $stored,
                    $stored,
                    "[{$field}] maps [{$spelling}] onto two different values once case and spacing are folded.",
                );

                $seen[$folded] = $stored;
            }
        }
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
