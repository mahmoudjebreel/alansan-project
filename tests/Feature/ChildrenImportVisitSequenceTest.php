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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Several visits of the same child inside one Children upload.
 *
 * Each row is a visit, and its type follows the child's actual visit sequence:
 * the first visit is "new" and the ones after it are "follow-up", whatever the
 * sheet says and whatever order it lists them in. The relapse rule that the
 * form applies is the one applied here, untouched.
 */
class ChildrenImportVisitSequenceTest extends TestCase
{
    use RefreshDatabase;

    /** MUAC values that classify to each FI band (SAM <= 115 < MAM < 125 <= Normal). */
    private const MUAC_FOR_FI = [
        'SAM' => 110,
        'MAM' => 120,
        'Normal' => 130,
    ];

    private const CHILD_ID = '405060708';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        \Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

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

        $name = 'sequence-test-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return \Storage::disk('local')->path($name);
    }

    private function import(array $rows): array
    {
        return app(ExcelImportService::class)->import(
            ImportDefinition::get('children'),
            $this->makeSheet($rows),
        );
    }

    /**
     * A sheet of visits for one child, as [date, visit type label, muac] rows.
     *
     * @param  array<int, array{0: string, 1: string, 2: int|float|null}>  $visits
     */
    private function sheet(array $visits): array
    {
        $headings = (new ImportSchema(ImportDefinition::get('children')))->headings();
        $rows = [$headings];

        foreach ($visits as [$date, $visitType, $muac]) {
            $values = [
                __('fields.visit_type') => $visitType,
                __('fields.child_id') => self::CHILD_ID,
                __('fields.name') => 'أمل حجاج',
                __('fields.sex') => __('fields.female'),
                __('fields.governorate') => 'Gaza',
                __('fields.organization') => 'AEI',
                __('fields.implementing_partner') => 'SCI',
                __('fields.date_of_reporting') => $date,
                __('fields.muac_mm') => $muac,
            ];

            $row = array_fill(0, count($headings), null);

            foreach ($values as $heading => $value) {
                $index = array_search($heading, $headings, true);
                $this->assertNotFalse($index, "No [{$heading}] column in the children template.");
                $row[$index] = $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The stored visits of the child, oldest first, as date => visit type.
     *
     * @return array<string, string>
     */
    private function storedVisits(): array
    {
        return Child::query()
            ->where('child_id', self::CHILD_ID)
            ->orderBy('date_of_reporting')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Child $visit): array => [
                $visit->date_of_reporting->format('Y-m-d') => $visit->visit_type,
            ])
            ->all();
    }

    // -----------------------------------------------------------------
    // Test case 1: a correct sheet keeps its second visit a follow-up.
    // -----------------------------------------------------------------

    public function test_a_second_visit_in_the_same_file_stays_a_follow_up(): void
    {
        $result = $this->import($this->sheet([
            ['2026-06-08', __('fields.new'), 130],
            ['2026-07-28', __('fields.follow_up'), 132],
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);

        $this->assertSame([
            '2026-06-08' => 'new',
            '2026-07-28' => 'follow_up',
        ], $this->storedVisits());
    }

    // -----------------------------------------------------------------
    // Test case 2: a sheet that swaps the two is corrected.
    // -----------------------------------------------------------------

    public function test_a_file_that_swaps_new_and_follow_up_is_corrected_by_the_visit_sequence(): void
    {
        $result = $this->import($this->sheet([
            ['2026-06-08', __('fields.follow_up'), 130],
            ['2026-07-28', __('fields.new'), 132],
        ]));

        $this->assertSame([], $result['errors']);

        $this->assertSame([
            '2026-06-08' => 'new',
            '2026-07-28' => 'follow_up',
        ], $this->storedVisits());
    }

    public function test_visits_are_sequenced_by_date_not_by_row_order(): void
    {
        // The later visit is listed first.
        $result = $this->import($this->sheet([
            ['2026-07-28', __('fields.follow_up'), 132],
            ['2026-06-08', __('fields.new'), 130],
        ]));

        $this->assertSame([], $result['errors']);

        $this->assertSame([
            '2026-06-08' => 'new',
            '2026-07-28' => 'follow_up',
        ], $this->storedVisits());
    }

    // -----------------------------------------------------------------
    // Test cases 3, 4 and 5: the first visit is new whatever its FI band.
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function fiBands(): array
    {
        return [
            'SAM' => ['SAM'],
            'MAM' => ['MAM'],
            'Normal' => ['Normal'],
        ];
    }

    #[DataProvider('fiBands')]
    public function test_a_first_visit_is_new_whatever_the_sheet_and_the_fi_say(string $fi): void
    {
        $muac = self::MUAC_FOR_FI[$fi];

        $result = $this->import($this->sheet([
            // The sheet wrongly calls the child's very first visit a follow-up.
            ['2026-06-08', __('fields.follow_up'), $muac],
            // A stable reading a month later is a follow-up of it.
            ['2026-07-28', __('fields.new'), $muac],
        ]));

        $this->assertSame([], $result['errors']);

        $this->assertSame([
            '2026-06-08' => 'new',
            '2026-07-28' => 'follow_up',
        ], $this->storedVisits());

        $first = Child::where('child_id', self::CHILD_ID)->orderBy('date_of_reporting')->firstOrFail();
        $this->assertSame($fi, $first->fi, 'FI stays derived from the MUAC of the visit itself.');
    }

    // -----------------------------------------------------------------
    // Test case 6: a longer sequence.
    // -----------------------------------------------------------------

    public function test_every_visit_after_the_first_is_a_follow_up(): void
    {
        $result = $this->import($this->sheet([
            ['2026-06-08', __('fields.new'), 130],
            ['2026-07-28', __('fields.new'), 131],
            ['2026-08-15', __('fields.new'), 132],
            ['2026-09-20', __('fields.new'), 133],
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(4, $result['imported']);

        $this->assertSame([
            '2026-06-08' => 'new',
            '2026-07-28' => 'follow_up',
            '2026-08-15' => 'follow_up',
            '2026-09-20' => 'follow_up',
        ], $this->storedVisits());
    }

    public function test_a_visit_in_the_file_is_sequenced_after_a_visit_already_in_the_system(): void
    {
        Child::factory()->create([
            'child_id' => self::CHILD_ID,
            'date_of_reporting' => '2026-06-08',
            'muac_mm' => 130,
            'visit_type' => 'new',
        ]);

        $result = $this->import($this->sheet([
            ['2026-07-28', __('fields.new'), 132],
        ]));

        $this->assertSame([], $result['errors']);

        $this->assertSame([
            '2026-06-08' => 'new',
            '2026-07-28' => 'follow_up',
        ], $this->storedVisits());
    }

    /**
     * The relapse rule is the module's own and is left exactly as it is: a
     * later visit that has deteriorated is a new admission, in a file as on
     * the form.
     */
    public function test_the_existing_relapse_rule_still_applies_to_a_later_visit(): void
    {
        $result = $this->import($this->sheet([
            ['2026-06-08', __('fields.new'), self::MUAC_FOR_FI['Normal']],
            ['2026-07-28', __('fields.follow_up'), self::MUAC_FOR_FI['SAM']],
        ]));

        $this->assertSame([], $result['errors']);

        $this->assertSame([
            '2026-06-08' => 'new',
            '2026-07-28' => 'new',
        ], $this->storedVisits());
    }

    // -----------------------------------------------------------------
    // Test case 7: the same file uploaded twice.
    // -----------------------------------------------------------------

    public function test_uploading_the_same_file_again_does_not_duplicate_the_visits(): void
    {
        $sheet = $this->sheet([
            ['2026-06-08', __('fields.new'), 130],
            ['2026-07-28', __('fields.follow_up'), 132],
        ]);

        $first = $this->import($sheet);
        $this->assertSame([], $first['errors']);
        $this->assertSame(2, $first['imported']);

        $second = $this->import($sheet);
        $this->assertSame([], $second['errors']);
        $this->assertSame(0, $second['imported']);

        $this->assertSame(2, Child::where('child_id', self::CHILD_ID)->count());

        $this->assertSame([
            '2026-06-08' => 'new',
            '2026-07-28' => 'follow_up',
        ], $this->storedVisits());
    }

    public function test_a_new_visit_in_a_re_uploaded_file_is_added_as_a_follow_up(): void
    {
        $this->import($this->sheet([
            ['2026-06-08', __('fields.new'), 130],
        ]));

        // The same file, with one more month appended.
        $result = $this->import($this->sheet([
            ['2026-06-08', __('fields.new'), 130],
            ['2026-07-28', __('fields.new'), 132],
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $this->assertSame([
            '2026-06-08' => 'new',
            '2026-07-28' => 'follow_up',
        ], $this->storedVisits());
    }

    /**
     * A row of the file only stands in for a visit that is in the system; a
     * trashed visit is not, so the row is written and starts the child over.
     */
    public function test_a_trashed_visit_does_not_block_its_re_upload(): void
    {
        Child::factory()->create([
            'child_id' => self::CHILD_ID,
            'date_of_reporting' => '2026-06-08',
            'muac_mm' => 130,
        ])->delete();

        $result = $this->import($this->sheet([
            ['2026-06-08', __('fields.follow_up'), 130],
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(['2026-06-08' => 'new'], $this->storedVisits());
    }
}
