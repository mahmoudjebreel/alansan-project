<?php

namespace Tests\Feature;

use App\Events\ExcelActionOccurred;
use App\Filament\Pages\Backups;
use App\Filament\Pages\MealReport;
use App\Filament\Resources\ChildResource\Pages\ListChildren;
use App\Models\Child;
use App\Models\User;
use App\Support\Notifications\ActionType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Discrete audit events land in the existing activity_log, one row each,
 * and the rows the six modules already write are left exactly as they were.
 */
class UserActivityAuditEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('Super Admin');
        $this->actingAs($this->user);
    }

    public function test_a_pdf_export_writes_one_export_row_and_no_notification(): void
    {
        $notificationsBefore = DatabaseNotification::count();

        Livewire::test(ListChildren::class)->call('downloadPdf');

        $rows = Activity::where('log_name', 'export')->where('event', 'pdf_export')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Child', $rows->first()->properties['module']);
        $this->assertSame($this->user->id, (int) $rows->first()->causer_id);
        $this->assertSame('127.0.0.1', $rows->first()->properties['ip'] ?? null);

        // PDF exports are audited, not announced: the notification pipeline
        // is untouched.
        $this->assertSame($notificationsBefore, DatabaseNotification::count());
    }

    public function test_an_excel_export_event_writes_one_excel_row(): void
    {
        ExcelActionOccurred::dispatch('Child', ActionType::EXPORT, $this->user);

        $rows = Activity::where('log_name', 'excel')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('export', $rows->first()->event);
        $this->assertSame('Child', $rows->first()->properties['module']);
    }

    public function test_an_excel_import_event_writes_one_excel_row_with_the_count(): void
    {
        ExcelActionOccurred::dispatch('Child', ActionType::IMPORT, $this->user, 240);

        $row = Activity::where('log_name', 'excel')->where('event', 'import')->first();
        $this->assertNotNull($row);
        $this->assertSame(240, $row->properties['record_count']);
    }

    public function test_a_meal_export_writes_one_export_row(): void
    {
        Livewire::test(MealReport::class)
            ->fillForm([
                'year' => 2026,
                'from_month' => 7,
                'to_month' => 7,
                'site' => 'Mossab Camp',
            ])
            ->call('export');

        $row = Activity::where('log_name', 'export')->where('event', 'meal_export')->first();
        $this->assertNotNull($row, 'The MEAL export was not written to activity_log.');
        $this->assertSame('Mossab Camp', $row->properties['site']);
        $this->assertSame($this->user->id, (int) $row->causer_id);
    }

    public function test_a_backup_download_writes_one_backup_row(): void
    {
        $path = storage_path('app/test-backup-audit.sql');
        file_put_contents($path, "-- test\n");

        try {
            Livewire::test(Backups::class)->call('downloadBackup', $path);
        } finally {
            @unlink($path);
        }

        $row = Activity::where('log_name', 'backup')->where('event', 'backup_downloaded')->first();
        $this->assertNotNull($row, 'The backup download was not written to activity_log.');
        $this->assertSame('test-backup-audit.sql', $row->properties['filename']);
    }

    public function test_the_existing_model_logging_is_unchanged(): void
    {
        $before = Activity::count();

        $child = Child::factory()->create();
        $child->update(['name' => 'Renamed']);

        $rows = Activity::where('subject_type', Child::class)->where('subject_id', $child->id)->get();

        $this->assertSame(2, Activity::count() - $before);
        $this->assertSame(['created', 'updated'], $rows->pluck('event')->all());
        $this->assertSame('default', $rows->first()->log_name);
        $this->assertArrayHasKey('attributes', $rows->last()->properties->toArray());
        $this->assertArrayHasKey('old', $rows->last()->properties->toArray());
    }
}
