<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\MotherToMotherSession;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\ImportSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ExcelImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Keep generated spreadsheets out of the real storage directory.
        \Storage::fake('local');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /**
     * Write a sheet (headings + rows) to a real .xlsx file and return its path.
     */
    private function makeSheet(array $rows): string
    {
        $export = new class($rows) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'import-test-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return \Storage::disk('local')->path($name);
    }

    private function import(string $key, array $rows): array
    {
        return app(ExcelImportService::class)->import(
            ImportDefinition::get($key),
            $this->makeSheet($rows),
        );
    }

    public function test_template_headings_match_the_export_columns(): void
    {
        foreach (ImportDefinition::all() as $key => $definition) {
            $schema = new ImportSchema($definition);
            $exportHeadings = $definition->exporter()->headings();

            foreach ($definition->fields() as $field) {
                $this->assertContains(
                    __('fields.' . $field),
                    $exportHeadings,
                    "Template column for [{$field}] in [{$key}] is not produced by the export.",
                );
            }
        }
    }

    public function test_valid_file_imports_every_row(): void
    {
        $definition = ImportDefinition::get('children');
        $headings = (new ImportSchema($definition))->headings();

        $rows = [$headings];

        foreach (['CH-100', 'CH-200'] as $i => $id) {
            $row = array_fill(0, count($headings), null);
            $row[array_search(__('fields.visit_type'), $headings, true)] = __('fields.new');
            $row[array_search(__('fields.child_id'), $headings, true)] = $id;
            $row[array_search(__('fields.name'), $headings, true)] = 'Child ' . $i;
            $row[array_search(__('fields.date_of_reporting'), $headings, true)] = '2026-01-15';
            $row[array_search(__('fields.sex'), $headings, true)] = __('fields.male');
            $row[array_search(__('fields.organization'), $headings, true)] = 'Org';
            $row[array_search(__('fields.implementing_partner'), $headings, true)] = 'Partner';
            $row[array_search(__('fields.governorate'), $headings, true)] = 'Gaza';
            $rows[] = $row;
        }

        $result = $this->import('children', $rows);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, Child::count());
        $this->assertSame('new', Child::where('child_id', 'CH-100')->first()->visit_type);
    }

    public function test_computed_fi_is_recalculated_and_never_taken_from_the_file(): void
    {
        $definition = ImportDefinition::get('children');
        $headings = (new ImportSchema($definition))->headings();

        // Deliberately add an FI column with a wrong value; it must be ignored.
        $headings[] = __('fields.fi');

        $row = array_fill(0, count($headings), null);
        $row[array_search(__('fields.visit_type'), $headings, true)] = __('fields.new');
        $row[array_search(__('fields.child_id'), $headings, true)] = 'CH-MUAC';
        $row[array_search(__('fields.name'), $headings, true)] = 'Muac Child';
        $row[array_search(__('fields.date_of_reporting'), $headings, true)] = '2026-01-15';
        $row[array_search(__('fields.sex'), $headings, true)] = __('fields.male');
        $row[array_search(__('fields.organization'), $headings, true)] = 'Org';
        $row[array_search(__('fields.implementing_partner'), $headings, true)] = 'Partner';
        $row[array_search(__('fields.governorate'), $headings, true)] = 'Gaza';
        $row[array_search(__('fields.muac_mm'), $headings, true)] = 110;   // => SAM
        $row[array_search(__('fields.fi'), $headings, true)] = 'Normal';   // lie

        $result = $this->import('children', [$headings, $row]);

        $this->assertSame([], $result['errors']);
        $child = Child::where('child_id', 'CH-MUAC')->first();
        $this->assertSame('SAM', $child->fi, 'FI must be derived from MUAC, not read from the file.');
    }

    public function test_an_invalid_row_cancels_the_whole_file_and_is_reported(): void
    {
        $definition = ImportDefinition::get('children');
        $headings = (new ImportSchema($definition))->headings();

        $good = array_fill(0, count($headings), null);
        $good[array_search(__('fields.visit_type'), $headings, true)] = __('fields.new');
        $good[array_search(__('fields.child_id'), $headings, true)] = 'CH-OK';
        $good[array_search(__('fields.name'), $headings, true)] = 'Good Row';
        $good[array_search(__('fields.date_of_reporting'), $headings, true)] = '2026-01-15';
        $good[array_search(__('fields.sex'), $headings, true)] = __('fields.male');
        $good[array_search(__('fields.organization'), $headings, true)] = 'Org';
        $good[array_search(__('fields.implementing_partner'), $headings, true)] = 'Partner';
        $good[array_search(__('fields.governorate'), $headings, true)] = 'Gaza';

        // Invalid Select value for Sex.
        $bad = $good;
        $bad[array_search(__('fields.child_id'), $headings, true)] = 'CH-BAD';
        $bad[array_search(__('fields.sex'), $headings, true)] = 'Martian';

        // Missing a required field (name).
        $missing = $good;
        $missing[array_search(__('fields.child_id'), $headings, true)] = 'CH-MISSING';
        $missing[array_search(__('fields.name'), $headings, true)] = null;

        $result = $this->import('children', [$headings, $good, $bad, $missing]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, Child::count(), 'Strict all-or-nothing: nothing may be written.');
        $this->assertCount(2, $result['errors']);

        // Errors name the offending sheet row and the reason.
        $this->assertStringContainsString('3', $result['errors'][0]);
        $this->assertStringContainsString(__('fields.sex'), $result['errors'][0]);
        $this->assertStringContainsString('4', $result['errors'][1]);
        $this->assertStringContainsString(__('fields.name'), $result['errors'][1]);
    }

    public function test_follow_up_child_imports_visit_columns(): void
    {
        $definition = ImportDefinition::get('follow_up_children');
        $headings = (new ImportSchema($definition))->headings();

        $row = array_fill(0, count($headings), null);
        $row[array_search(__('fields.id_number'), $headings, true)] = 'FUC-1';
        $row[array_search(__('fields.child_name'), $headings, true)] = 'Followed Child';
        $row[array_search(__('fields.governorate'), $headings, true)] = 'Gaza';
        $row[array_search(__('fields.visit_date_n', ['n' => 1]), $headings, true)] = '2026-02-01';
        $row[array_search(__('fields.visit_muac_n', ['n' => 1]), $headings, true)] = 118;
        $row[array_search(__('fields.visit_date_n', ['n' => 2]), $headings, true)] = '2026-03-01';
        $row[array_search(__('fields.visit_muac_n', ['n' => 2]), $headings, true)] = 124;

        $result = $this->import('follow_up_children', [$headings, $row]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $child = FollowUpChild::where('id_number', 'FUC-1')->first();
        $this->assertNotNull($child);
        $this->assertCount(2, $child->visits);
        $this->assertSame(1, $child->visits->first()->visit_number);
        $this->assertSame('2026-02-01', $child->visits->first()->visit_date->format('Y-m-d'));
    }

    public function test_english_headings_are_accepted_too(): void
    {
        $definition = ImportDefinition::get('children');

        $headings = array_map(
            fn (string $f): string => trans('fields.' . $f, [], 'en'),
            $definition->fields(),
        );

        $row = array_fill(0, count($headings), null);
        $en = fn (string $f): int => (int) array_search(trans('fields.' . $f, [], 'en'), $headings, true);
        $row[$en('visit_type')] = 'new';
        $row[$en('child_id')] = 'CH-EN';
        $row[$en('name')] = 'English Row';
        $row[$en('date_of_reporting')] = '2026-01-15';
        $row[$en('sex')] = 'male';
        $row[$en('organization')] = 'Org';
        $row[$en('implementing_partner')] = 'Partner';
        $row[$en('governorate')] = 'Gaza';

        $result = $this->import('children', [$headings, $row]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
    }

    public function test_missing_required_columns_are_reported(): void
    {
        // Only one column present: the required ones are missing.
        $result = $this->import('children', [[__('fields.name')], ['Someone']]);

        $this->assertSame(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString(__('fields.child_id'), $result['errors'][0]);
    }

    /**
     * The strongest check: download the real template, fill one row in it, and
     * upload it back. The grey guidance row must be tolerated, not rejected.
     */
    public function test_generated_template_round_trips(): void
    {
        $definition = ImportDefinition::get('children');

        Excel::store(new \App\Imports\ImportTemplateExport($definition), 'tpl.xlsx', 'local');
        $sheet = Excel::toArray(new class implements \Maatwebsite\Excel\Concerns\ToArray
        {
            public function array(array $rows): array
            {
                return $rows;
            }
        }, \Storage::disk('local')->path('tpl.xlsx'))[0];

        $headings = $sheet[0];
        $guidance = $sheet[1];          // kept in place on purpose
        $at = fn (string $f): int => (int) array_search(__('fields.' . $f), $headings, true);

        $row = array_fill(0, count($headings), null);
        $row[$at('visit_type')] = __('fields.new');
        $row[$at('child_id')] = 'CH-RT';
        $row[$at('name')] = 'Round Trip';
        $row[$at('date_of_reporting')] = '2026-01-15';
        $row[$at('sex')] = __('fields.female');
        $row[$at('organization')] = 'Org';
        $row[$at('implementing_partner')] = 'Partner';
        $row[$at('governorate')] = 'Gaza';

        $result = $this->import('children', [$headings, $guidance, $row]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported'], 'The guidance row must be skipped, not imported.');
        $this->assertSame('female', Child::where('child_id', 'CH-RT')->first()->sex);
    }

    /**
     * Every column a module declares as an enum has to expose the option list an
     * uploaded label is translated back through. When one does not, the cell is
     * written to the database unchecked - which is exactly how a Category label
     * reached SQL raw and failed with "Data truncated for column 'category'".
     */
    public function test_every_enum_column_exposes_its_option_list(): void
    {
        foreach (ImportDefinition::all() as $key => $definition) {
            $schema = new ImportSchema($definition);

            foreach ($definition->enumFields() as $field) {
                $this->assertNotEmpty(
                    $schema->optionsFor($field),
                    "Column [{$field}] in [{$key}] declares an enum but exposes no options, "
                    . 'so an uploaded label cannot be validated or translated back.',
                );
            }
        }
    }

    /**
     * Both session modules accept every Category the form offers, written the way
     * the export writes it: the Arabic label. "ذكر" is the label of the Male
     * category, not a gender column bleeding into the wrong cell.
     */
    public function test_session_modules_import_every_category_label(): void
    {
        foreach (['group_sessions' => GroupSession::class, 'mother_to_mother' => MotherToMotherSession::class] as $key => $model) {
            $definition = ImportDefinition::get($key);
            $categories = array_keys((new ImportSchema($definition))->optionsFor('category'));

            $rows = [$this->sessionHeadings($key)];

            foreach ($categories as $i => $category) {
                $rows[] = $this->sessionRow($key, [
                    'id_number' => '40800000' . $i,
                    'category' => __('fields.' . $category),
                    // The two caregiver categories and Pregnant carry a newborn DOB.
                    'newborn_dob' => '2026-01-05',
                ]);
            }

            $result = $this->import($key, $rows);

            $this->assertSame([], $result['errors'], "[{$key}] rejected a valid Category.");
            $this->assertSame(count($categories), $result['imported']);

            foreach ($categories as $i => $category) {
                $this->assertSame(
                    $category,
                    $model::where('id_number', '40800000' . $i)->first()->category,
                    "[{$key}] stored the wrong value for the [{$category}] label.",
                );
            }
        }
    }

    /**
     * A Category the form does not offer is refused with a message naming the row,
     * the column and the accepted values - never a raw SQL warning.
     */
    public function test_an_invalid_category_is_reported_instead_of_reaching_sql(): void
    {
        foreach (['group_sessions' => GroupSession::class, 'mother_to_mother' => MotherToMotherSession::class] as $key => $model) {
            $result = $this->import($key, [
                $this->sessionHeadings($key),
                $this->sessionRow($key, ['category' => 'فئة غير معروفة']),
            ]);

            $this->assertSame(0, $result['imported']);
            $this->assertSame(0, $model::count(), 'Nothing may be written when a row is rejected.');
            $this->assertCount(1, $result['errors']);

            $error = $result['errors'][0];
            $this->assertStringContainsString('2', $error, 'The sheet row number must be named.');
            $this->assertStringContainsString(__('fields.category'), $error);
            $this->assertStringContainsString(__('fields.male'), $error, 'The accepted values must be listed.');
            $this->assertStringNotContainsString('SQLSTATE', $error);
        }
    }

    /**
     * The downloadable template tells the user what to type into every Select
     * column, Category included.
     */
    public function test_the_template_guidance_row_lists_the_category_options(): void
    {
        foreach (['group_sessions', 'mother_to_mother'] as $key) {
            $definition = ImportDefinition::get($key);
            $guidance = (new \App\Imports\ImportTemplateExport($definition))->array()[0];
            $hint = $guidance[array_search('category', $definition->fields(), true)];

            $this->assertStringContainsString(__('fields.male'), $hint);
            $this->assertStringContainsString(__('fields.grandmothers'), $hint);
        }
    }

    /**
     * Headings for one of the two session modules.
     */
    private function sessionHeadings(string $key): array
    {
        return (new ImportSchema(ImportDefinition::get($key)))->headings();
    }

    /**
     * A complete, valid session row, with the given columns overridden.
     */
    private function sessionRow(string $key, array $overrides = []): array
    {
        $definition = ImportDefinition::get($key);
        $fields = $definition->fields();
        $options = new ImportSchema($definition);

        $values = $overrides + [
            'session_date' => '2026-05-10',
            'session_group_number' => '476',
            'session_subject' => __('fields.relactation'),
            'locality' => __('fields.' . array_key_first($options->optionsFor('locality'))),
            'shelter_name' => $options->optionsFor('shelter_name')
                ? __('fields.' . array_key_first($options->optionsFor('shelter_name')))
                : 'Shelter',
            'id_number' => '408000719',
            'full_name_ar' => 'تشا كوت',
            'visit_type' => __('fields.new'),
            'category' => __('fields.grandmothers'),
            'is_pwd' => __('fields.no'),
            'marital_status' => __('fields.married'),
            'phone_number' => '0594075921',
        ];

        $row = array_fill(0, count($fields), null);

        foreach ($values as $field => $value) {
            $index = array_search($field, $fields, true);

            if ($index !== false) {
                $row[$index] = $value;
            }
        }

        return $row;
    }

    public function test_only_users_with_import_permission_may_import(): void
    {
        foreach (ImportDefinition::all() as $definition) {
            $this->actingAsRole('Admin');
            $this->assertTrue(auth()->user()->can($definition->permission));

            $this->actingAsRole('Data Entry');
            $this->assertTrue(auth()->user()->can($definition->permission));

            $this->actingAsRole('Viewer');
            $this->assertFalse(auth()->user()->can($definition->permission));
        }
    }
}
