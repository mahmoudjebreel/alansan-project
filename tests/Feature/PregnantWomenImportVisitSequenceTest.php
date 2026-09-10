<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Models\PregnantLactatingWoman;
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
 * Several visits of the same mother inside one Pregnant / Lactating Women
 * upload.
 *
 * The visit type follows the status transition between a visit and the one
 * stored before it - which, for the rows of one file, is the row above. The
 * status rule itself is the one the form applies, untouched; what is checked
 * here is that a row is compared with the previous row of the same file and
 * not only with the records from before the upload.
 */
class PregnantWomenImportVisitSequenceTest extends TestCase
{
    use RefreshDatabase;

    private const MOTHER_ID = '405060708';

    /** The English spelling of each stored status, as a workbook writes it. */
    private const SPELLING = [
        'pregnant' => 'Pregnant',
        'lactating' => 'Breastfeeding',
        'pregnant_lactating' => 'Pregnant + Breastfeeding',
    ];

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
     * One data row for the mother: her status on a given reporting date.
     */
    private function row(string $statusType, string $dateOfReporting): array
    {
        $headings = $this->headings();
        $row = array_fill(0, count($headings), null);

        $cells = [
            __('fields.mother_id') => self::MOTHER_ID,
            __('fields.full_name_ar') => 'أم الاستيراد',
            __('fields.phone_number') => '0591234567',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => $dateOfReporting,
            __('fields.date_of_birth') => '1996-01-01',
            __('fields.governorate') => 'gaza',
            __('fields.status_type') => self::SPELLING[$statusType],
            __('fields.status') => 'متزوجة',
        ];

        foreach ($cells as $heading => $value) {
            $index = array_search($heading, $headings, true);

            if ($index !== false) {
                $row[$index] = $value;
            }
        }

        return $row;
    }

    /**
     * @return array{imported: int, errors: array<string>}
     */
    private function import(array $rows): array
    {
        $export = new class([$this->headings(), ...$rows]) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'plw-sequence-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            $this->definition(),
            \Storage::disk('local')->path($name),
        );
    }

    /**
     * The mother's stored visits, oldest first, as "status => visit type".
     *
     * @return array<int, string>
     */
    private function storedSequence(): array
    {
        return PregnantLactatingWoman::query()
            ->where('mother_id', self::MOTHER_ID)
            ->orderBy('date_of_reporting')
            ->orderBy('id')
            ->get()
            ->map(fn (PregnantLactatingWoman $visit): string => $visit->status_type . ' => ' . $visit->visit_type)
            ->all();
    }

    // -----------------------------------------------------------------
    // The reported file: pregnant on record, then P+L and P in one upload
    // -----------------------------------------------------------------

    public function test_pregnant_under_pregnant_lactating_in_the_same_file_is_new(): void
    {
        PregnantLactatingWoman::factory()->create([
            'mother_id' => self::MOTHER_ID,
            'status_type' => 'pregnant',
            'date_of_reporting' => '2026-01-01',
        ]);

        $result = $this->import([
            $this->row('pregnant_lactating', '2026-03-01'),
            $this->row('pregnant', '2026-04-01'),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);

        // The last row is compared with the P+L row above it, not with the
        // pregnant record from before the upload.
        $this->assertSame([
            'pregnant => new',
            'pregnant_lactating => new',
            'pregnant => new',
        ], $this->storedSequence());
    }

    // -----------------------------------------------------------------
    // The whole matrix, each transition read from two rows of one file
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function statusTransitionMatrix(): array
    {
        return [
            'pregnant to pregnant' => ['pregnant', 'pregnant', 'follow_up'],
            'pregnant to lactating' => ['pregnant', 'lactating', 'new'],
            'pregnant to pregnant + lactating' => ['pregnant', 'pregnant_lactating', 'new'],
            'lactating to lactating' => ['lactating', 'lactating', 'follow_up'],
            'lactating to pregnant' => ['lactating', 'pregnant', 'new'],
            'lactating to pregnant + lactating' => ['lactating', 'pregnant_lactating', 'new'],
            'pregnant + lactating to pregnant + lactating' => ['pregnant_lactating', 'pregnant_lactating', 'follow_up'],
            'pregnant + lactating to pregnant' => ['pregnant_lactating', 'pregnant', 'new'],
            'pregnant + lactating to lactating' => ['pregnant_lactating', 'lactating', 'new'],
        ];
    }

    #[DataProvider('statusTransitionMatrix')]
    public function test_a_row_is_classified_against_the_row_above_it(string $previous, string $current, string $expected): void
    {
        $result = $this->import([
            $this->row($previous, '2026-03-01'),
            $this->row($current, '2026-04-01'),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);

        $this->assertSame([
            $previous . ' => new',
            $current . ' => ' . $expected,
        ], $this->storedSequence());
    }

    #[DataProvider('statusTransitionMatrix')]
    public function test_a_row_is_classified_against_the_record_stored_before_the_upload(string $previous, string $current, string $expected): void
    {
        PregnantLactatingWoman::factory()->create([
            'mother_id' => self::MOTHER_ID,
            'status_type' => $previous,
            'date_of_reporting' => '2026-01-01',
        ]);

        $result = $this->import([$this->row($current, '2026-04-01')]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $this->assertSame($expected, PregnantLactatingWoman::query()
            ->where('mother_id', self::MOTHER_ID)
            ->orderByDesc('id')
            ->firstOrFail()
            ->visit_type);
    }
}
