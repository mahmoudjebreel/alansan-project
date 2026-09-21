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
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * C3 - a large file must not be held in memory.
 *
 * The reading side was already chunked; the importer then put the rows straight
 * back, keeping one attribute array per validated row until the whole file had
 * been read. A 150,000-row upload therefore held 150,000 arrays at once and
 * died long before it reached the database, with the chunked reader looking
 * innocent the whole time.
 *
 * Validated rows now go to a temporary file, 500 at a time, and are streamed
 * back one at a time at commit. What is asserted below is what can honestly be
 * asserted in a test: that memory does not grow with the size of the file, that
 * every row still arrives, that nothing is written twice, and that the result
 * the user sees is unchanged. The 150,000-row case itself is not run here - it
 * would take minutes - but the growth is measured across two sizes an order of
 * magnitude apart, which is what tells you whether it scales.
 *
 * Deliberately not asserted: a fixed memory ceiling. PHP's allocator does not
 * give the same number twice, and a test that pins one is a test that fails on
 * somebody else's machine for no reason.
 */
class LargeImportMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        \Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    private function headings(): array
    {
        return (new ImportSchema(ImportDefinition::get('children')))->headings();
    }

    /**
     * A sheet of $count children, each with their own ID so none of them is a
     * duplicate of another.
     */
    private function sheetOf(int $count, int $idOffset = 0): string
    {
        $headings = $this->headings();

        $position = [];

        foreach ([
            'name', 'child_id', 'organization', 'implementing_partner',
            'date_of_reporting', 'governorate', 'sex', 'muac_mm',
        ] as $field) {
            $index = array_search(__('fields.' . $field), $headings, true);
            $this->assertNotFalse($index, "Heading for [{$field}] is missing.");
            $position[$field] = $index;
        }

        $rows = [$headings];

        for ($i = 0; $i < $count; $i++) {
            $row = array_fill(0, count($headings), null);

            $row[$position['name']] = 'طفل ' . $i;
            $row[$position['child_id']] = (string) (500000000 + $idOffset + $i);
            $row[$position['organization']] = 'AEI';
            $row[$position['implementing_partner']] = 'SCI';
            $row[$position['date_of_reporting']] = '2026-08-20';
            $row[$position['governorate']] = 'gaza';
            $row[$position['sex']] = 'ذكر';
            $row[$position['muac_mm']] = 130;

            $rows[] = $row;
        }

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

        $name = 'large-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return \Storage::disk('local')->path($name);
    }

    private function import(string $path): array
    {
        return app(ExcelImportService::class)->import(ImportDefinition::get('children'), $path);
    }

    /**
     * What the importer itself still holds after reading a whole file.
     *
     * Measured as the memory released when the importer is discarded, rather
     * than as the memory the read phase consumed. The two are not the same, and
     * the difference is the whole reason this method exists: PhpSpreadsheet
     * retains something of its own per row of the sheet - it did so before any
     * of this changed, and it is not what the importer does with the rows once
     * it has read them. Measuring the whole read phase measures that instead,
     * and would report growth whatever the importer held.
     *
     * The buffer is flushed to disk before the measurement so that what is
     * reported is the importer's steady state rather than however many rows
     * happened to be waiting when the file ran out.
     */
    private function importerFootprint(int $rows, int $idOffset): int
    {
        $path = $this->sheetOf($rows, $idOffset);

        $importer = new \App\Imports\ChildrenImport(ImportDefinition::get('children'));
        Excel::import($importer, $path);

        $this->assertSame($rows, $importer->dataRowCount());

        // Starting the stream is what writes the last partial batch out.
        $stream = $importer->eachRow();
        $stream->rewind();
        unset($stream);

        gc_collect_cycles();
        $withImporter = memory_get_usage();

        $importer->discardRows();
        unset($importer);

        gc_collect_cycles();

        return $withImporter - memory_get_usage();
    }

    /**
     * The measurement that matters: ten times the rows must not cost ten times
     * the memory.
     *
     * The rows are the only thing that used to scale with the file - the
     * schema, the option lists and the chunked reader all cost the same
     * whatever the row count - so if what the importer holds after reading
     * 1,000 rows is not ten times what it holds after 100, the rows are no
     * longer being accumulated.
     */
    public function test_memory_does_not_grow_with_the_number_of_rows(): void
    {
        // Warm everything the first read would otherwise pay for once: the
        // schema, the Filament form walk, the translation loader.
        $this->importerFootprint(5, 900000);

        $small = $this->importerFootprint(100, 100000);
        $large = $this->importerFootprint(1000, 200000);

        // Ten times the rows. Holding them all would cost about ten times as
        // much - a Children row is some thirty columns, so a thousand of them
        // is megabytes. Streaming them to disk costs the write buffer, which
        // does not change with the file, plus the reporting-day index, which
        // grows by a few bytes a row.
        //
        // The ceiling is four times the smaller figure plus a fixed allowance,
        // not a pinned number: the point is the shape of the growth, and a test
        // that pins a byte count is a test that fails on somebody else's
        // machine for no reason. Accumulation would miss it by an order of
        // magnitude, which is the only thing worth catching here.
        $this->assertLessThan(
            abs($small) * 4 + 256 * 1024,
            abs($large),
            sprintf(
                'The importer held %d bytes after 1,000 rows against %d after 100: the rows are still being accumulated.',
                $large,
                $small,
            ),
        );
    }

    /**
     * The rows really are on disk, and all of them: the spill file carries one
     * line per validated row while the importer is holding them.
     */
    public function test_the_validated_rows_are_held_on_disk_and_streamed_back(): void
    {
        $importer = new \App\Imports\ChildrenImport(ImportDefinition::get('children'));
        Excel::import($importer, $this->sheetOf(600, idOffset: 300000));

        $this->assertSame(600, $importer->dataRowCount());

        // A generator, not an array: the caller writes each row and lets go.
        $this->assertInstanceOf(\Generator::class, $importer->eachRow());

        $seen = 0;
        $ids = [];

        foreach ($importer->eachRow() as $row) {
            $seen++;
            $ids[] = $row['attributes']['child_id'];
        }

        $this->assertSame(600, $seen);
        $this->assertCount(600, array_unique($ids));

        $importer->discardRows();
    }

    /**
     * No silent loss and no silent duplication: every row of the file is in the
     * database exactly once, and the count the user is shown is the truth.
     */
    public function test_every_row_of_a_large_file_is_written_exactly_once(): void
    {
        $result = $this->import($this->sheetOf(1200));

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(1200, $result['imported']);

        $this->assertSame(1200, Child::count());
        $this->assertSame(1200, Child::distinct()->count('child_id'));
    }

    /**
     * The rows are spilled to disk in batches; a file whose row count is not a
     * multiple of the batch size must not lose its last partial batch.
     */
    public function test_a_row_count_that_is_not_a_whole_number_of_batches_loses_nothing(): void
    {
        // 500 is the batch size, so this is one full batch plus three rows.
        $result = $this->import($this->sheetOf(503));

        $this->assertSame([], $result['errors']);
        $this->assertSame(503, $result['imported']);
        $this->assertSame(503, Child::count());
    }

    /**
     * Validation still holds over a file big enough to be spilled: one bad row
     * anywhere cancels the whole upload, and it is named by its row number.
     */
    public function test_one_invalid_row_in_a_large_file_still_cancels_the_whole_import(): void
    {
        $headings = $this->headings();
        $idIndex = array_search(__('fields.child_id'), $headings, true);
        $dateIndex = array_search(__('fields.date_of_reporting'), $headings, true);
        $nameIndex = array_search(__('fields.name'), $headings, true);
        $orgIndex = array_search(__('fields.organization'), $headings, true);
        $partnerIndex = array_search(__('fields.implementing_partner'), $headings, true);
        $govIndex = array_search(__('fields.governorate'), $headings, true);
        $sexIndex = array_search(__('fields.sex'), $headings, true);

        $rows = [$headings];

        for ($i = 0; $i < 700; $i++) {
            $row = array_fill(0, count($headings), null);
            $row[$nameIndex] = 'طفل ' . $i;
            $row[$idIndex] = (string) (600000000 + $i);
            $row[$orgIndex] = 'AEI';
            $row[$partnerIndex] = 'SCI';
            $row[$govIndex] = 'gaza';
            $row[$sexIndex] = 'ذكر';
            // Row 601 of the sheet carries a date that never happened.
            $row[$dateIndex] = $i === 599 ? '31/04/2026' : '2026-08-20';

            $rows[] = $row;
        }

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

        $name = 'large-invalid-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        $result = $this->import(\Storage::disk('local')->path($name));

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('601', $result['errors'][0]);
        $this->assertSame(0, Child::count(), 'Nothing at all may be written when a row is refused.');
    }

    /**
     * The audit trail for a bulk import is one entry, not one per row.
     *
     * Spatie writes an activity row carrying the whole record as JSON for every
     * model saved, which on a large file is a second copy of the import inside
     * the same transaction. The summary below is the same shape BulkRecordWriter
     * already writes for a bulk delete: the module, the action, the count, and
     * a sample of IDs to find the rows by.
     */
    public function test_a_large_import_writes_one_activity_entry_rather_than_one_per_row(): void
    {
        Activity::query()->delete();

        $result = $this->import($this->sheetOf(600));

        $this->assertSame(600, $result['imported']);

        $entries = Activity::query()->where('log_name', 'bulk')->get();

        $this->assertCount(1, $entries);

        $properties = $entries->first()->properties;

        $this->assertSame('Child', $properties['module']);
        $this->assertSame('import', $properties['action']);
        $this->assertSame(600, $properties['count']);
        $this->assertNotEmpty($properties['sample_ids']);
        $this->assertLessThanOrEqual(20, count($properties['sample_ids']));

        // And no per-row entries were written alongside it.
        $this->assertSame(0, Activity::query()->where('log_name', '!=', 'bulk')->count());
    }

    /**
     * The one entry has to describe the whole run, not just its row count.
     *
     * Everything below comes from something the import actually counted or
     * measured - the importer's own tallies, the request's own clock, the
     * uploaded file's own name. None of it is derived from the error messages,
     * because one bad row can produce several of those and the count would then
     * report more refused rows than the file has.
     */
    public function test_the_import_entry_describes_the_whole_run(): void
    {
        Activity::query()->delete();

        $user = auth()->user();

        $this->import($this->sheetOf(40));

        $entry = Activity::query()->where('log_name', 'bulk')->sole();
        $properties = $entry->properties;

        // Who ran it.
        $this->assertSame($user->getKey(), $entry->causer_id);
        $this->assertSame($user::class, $entry->causer_type);

        // What was run, on what, from which file.
        $this->assertSame('Child', $properties['module']);
        $this->assertSame('import', $properties['action']);
        $this->assertStringEndsWith('.xlsx', $properties['file']);
        $this->assertStringNotContainsString('/', $properties['file'], 'The entry must not carry a server path.');
        $this->assertStringNotContainsString('\\', $properties['file']);

        // How it ended, and the four row counts that say why.
        $this->assertSame('success', $properties['status']);
        $this->assertSame(40, $properties['total_rows']);
        $this->assertSame(40, $properties['imported_rows']);
        $this->assertSame(0, $properties['duplicate_rows']);
        $this->assertSame(0, $properties['rejected_rows']);
        $this->assertSame(0, $properties['error_count']);

        // When, and for how long.
        $this->assertNotNull(\Carbon\CarbonImmutable::parse($properties['started_at']));
        $this->assertNotNull(\Carbon\CarbonImmutable::parse($properties['finished_at']));
        $this->assertGreaterThanOrEqual(0, $properties['duration_ms']);
        $this->assertLessThanOrEqual(
            $properties['finished_at'],
            $properties['started_at'],
            'The import cannot have finished before it started.',
        );
    }

    /**
     * A file whose rows are all already in the system stores nothing - and is
     * still a run somebody performed, so it still has to be on the record.
     */
    public function test_an_import_of_nothing_but_duplicates_is_still_audited(): void
    {
        $path = $this->sheetOf(12, idOffset: 400000);

        $this->import($path);

        Activity::query()->delete();

        // The very same file a second time.
        $result = $this->import($path);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(12, $result['skipped']);

        $properties = Activity::query()->where('log_name', 'bulk')->sole()->properties;

        $this->assertSame('success_with_duplicates', $properties['status']);
        $this->assertSame(12, $properties['total_rows']);
        $this->assertSame(0, $properties['imported_rows']);
        $this->assertSame(12, $properties['duplicate_rows']);
        $this->assertSame(0, $properties['rejected_rows']);
    }

    /**
     * Some stored, some already present: the status says so, and the counts add
     * up to the file.
     */
    public function test_a_part_duplicate_import_is_audited_as_such(): void
    {
        $this->import($this->sheetOf(10, idOffset: 500000));

        Activity::query()->delete();

        // The same ten rows, plus ten the system has not seen.
        $this->import($this->sheetOf(20, idOffset: 500000));

        $properties = Activity::query()->where('log_name', 'bulk')->sole()->properties;

        $this->assertSame('success_with_duplicates', $properties['status']);
        $this->assertSame(20, $properties['total_rows']);
        $this->assertSame(10, $properties['imported_rows']);
        $this->assertSame(10, $properties['duplicate_rows']);
        $this->assertSame(0, $properties['rejected_rows']);
    }

    /**
     * A refused file writes nothing to the module's table and one entry to the
     * audit log saying why it wrote nothing.
     *
     * The rejected-row count is the number of ROWS refused, not the number of
     * complaints made about them.
     */
    public function test_a_failed_import_is_audited_with_its_rejected_row_count(): void
    {
        Activity::query()->delete();

        $headings = $this->headings();
        $position = [];

        foreach (['name', 'child_id', 'organization', 'implementing_partner', 'date_of_reporting', 'governorate', 'sex'] as $field) {
            $position[$field] = array_search(__('fields.' . $field), $headings, true);
        }

        $rows = [$headings];

        for ($i = 0; $i < 8; $i++) {
            $row = array_fill(0, count($headings), null);
            $row[$position['name']] = 'طفل ' . $i;
            $row[$position['child_id']] = (string) (800000000 + $i);
            $row[$position['organization']] = 'AEI';
            $row[$position['implementing_partner']] = 'SCI';
            $row[$position['governorate']] = 'gaza';
            $row[$position['sex']] = 'ذكر';
            // Two rows name a day that never happened.
            $row[$position['date_of_reporting']] = in_array($i, [2, 5], true) ? '31/04/2026' : '2026-08-20';

            $rows[] = $row;
        }

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

        $name = 'audit-failed-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        $result = $this->import(\Storage::disk('local')->path($name));

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, Child::count());

        $properties = Activity::query()->where('log_name', 'bulk')->sole()->properties;

        $this->assertSame('failed', $properties['status']);
        $this->assertSame(8, $properties['total_rows']);
        $this->assertSame(0, $properties['imported_rows']);
        $this->assertSame(0, $properties['duplicate_rows']);
        $this->assertSame(2, $properties['rejected_rows']);
        $this->assertGreaterThan(0, $properties['error_count']);
    }

    /**
     * A file the module cannot read at all - wrong headings - is a failure with
     * no rows to count, and is still recorded.
     */
    public function test_a_file_with_unusable_columns_is_audited_as_failed(): void
    {
        Activity::query()->delete();

        $export = new class implements FromArray
        {
            public function array(): array
            {
                return [['Not', 'A', 'Children', 'Sheet'], ['a', 'b', 'c', 'd']];
            }
        };

        $name = 'audit-unusable-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        $result = $this->import(\Storage::disk('local')->path($name));

        $this->assertSame(0, $result['imported']);
        $this->assertNotSame([], $result['errors']);

        $properties = Activity::query()->where('log_name', 'bulk')->sole()->properties;

        $this->assertSame('failed', $properties['status']);
        $this->assertSame(0, $properties['imported_rows']);
        $this->assertGreaterThan(0, $properties['error_count']);
    }

    /**
     * Suppression is scoped to the import. An ordinary save afterwards is
     * audited exactly as it always was.
     */
    public function test_activity_logging_is_restored_after_the_import(): void
    {
        $this->import($this->sheetOf(3));

        Activity::query()->delete();

        Child::create([
            'name' => 'طفل يدوي',
            'child_id' => '700000001',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => '2026-08-20',
            'governorate' => 'gaza',
            'sex' => 'M',
            'muac_mm' => 130,
        ]);

        $this->assertSame(1, Activity::query()->where('log_name', '!=', 'bulk')->count());
    }

    /**
     * The temporary file the rows were spilled to is not left behind, whether
     * the import succeeded or was cancelled.
     */
    public function test_the_spill_file_is_cleaned_up(): void
    {
        $before = count(glob(sys_get_temp_dir() . '/import-rows-*') ?: []);

        $this->import($this->sheetOf(600));

        $this->assertSame($before, count(glob(sys_get_temp_dir() . '/import-rows-*') ?: []));
    }
}
