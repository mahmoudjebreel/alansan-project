<?php

namespace Tests\Feature;

use App\Events\ExcelActionOccurred;
use App\Filament\Pages\NotificationLogPage;
use App\Filament\Pages\NotificationSettingsPage;
use App\Jobs\DeliverDataActionNotification;
use App\Models\Child;
use App\Models\GroupSession;
use App\Models\User;
use App\Notifications\DataActionNotification;
use App\Settings\NotificationSettings;
use App\Support\Notifications\ActionNotifier;
use App\Support\Notifications\ActionType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DataActionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $dataEntry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create(['name' => 'مدير النظام']);
        $this->superAdmin->assignRole('Super Admin');

        $this->dataEntry = User::factory()->create(['name' => 'أحمد']);
        $this->dataEntry->assignRole('Data Entry');

        // Grouping off by default so each test asserts one action at a time;
        // the grouping tests turn it back on explicitly.
        $this->setWindow(0);
    }

    private function setWindow(int $seconds): void
    {
        $settings = app(NotificationSettings::class);
        $settings->group_window_seconds = $seconds;
        $settings->save();
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function payloads(): array
    {
        return DatabaseNotification::query()
            ->where('type', DataActionNotification::class)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (DatabaseNotification $n): array => $n->data)
            ->all();
    }

    // -----------------------------------------------------------------
    // Each action type notifies, with the right detail
    // -----------------------------------------------------------------

    public function test_create_notifies_with_the_full_payload(): void
    {
        $this->actingAs($this->dataEntry);

        Child::factory()->create(['child_id' => '123456789', 'name' => 'طفل']);

        $payloads = $this->payloads();
        $this->assertCount(1, $payloads);

        $payload = $payloads[0];
        $this->assertSame('أحمد', $payload['actor_name']);
        $this->assertSame('Data Entry', $payload['actor_role']);
        $this->assertSame(ActionType::CREATE, $payload['action_type']);
        $this->assertSame('Child', $payload['module']);
        $this->assertSame('الأطفال', $payload['module_label']);
        $this->assertStringContainsString('123456789', $payload['record_label']);
        $this->assertSame('low', $payload['priority']);
        $this->assertNotEmpty($payload['occurred_at']);
        $this->assertNotEmpty($payload['reference_url']);
        $this->assertStringContainsString('أحمد', $payload['body']);
    }

    public function test_update_notifies(): void
    {
        $child = Child::factory()->create();

        $this->actingAs($this->dataEntry);
        $child->update(['name' => 'اسم جديد']);

        $payloads = $this->payloads();
        $this->assertCount(1, $payloads);
        $this->assertSame(ActionType::UPDATE, $payloads[0]['action_type']);
        $this->assertSame('medium', $payloads[0]['priority']);
    }

    public function test_soft_delete_and_force_delete_are_reported_separately(): void
    {
        $soft = Child::factory()->create();
        $hard = Child::factory()->create();

        $this->actingAs($this->dataEntry);

        $soft->delete();
        $hard->forceDelete();

        $actions = array_column($this->payloads(), 'action_type');

        $this->assertContains(ActionType::DELETE, $actions);
        $this->assertContains(ActionType::FORCE_DELETE, $actions);

        foreach ($this->payloads() as $payload) {
            $this->assertSame('high', $payload['priority']);
            // A deleted record has no page to open.
            $this->assertNull($payload['reference_url']);
        }
    }

    public function test_restoring_is_not_reported_as_an_edit(): void
    {
        $child = Child::factory()->create();
        $child->delete();

        $this->actingAs($this->dataEntry);
        $child->restore();

        $this->assertSame([], array_column($this->payloads(), 'action_type'));
    }

    public function test_export_notifies(): void
    {
        $this->actingAs($this->dataEntry);

        ExcelActionOccurred::dispatch('Child', ActionType::EXPORT, $this->dataEntry);

        $payloads = $this->payloads();
        $this->assertCount(1, $payloads);
        $this->assertSame(ActionType::EXPORT, $payloads[0]['action_type']);
        $this->assertSame('medium', $payloads[0]['priority']);
    }

    public function test_import_notifies_once_with_a_row_count(): void
    {
        $this->actingAs($this->dataEntry);

        ExcelActionOccurred::dispatch('Child', ActionType::IMPORT, $this->dataEntry, 240);

        $payloads = $this->payloads();
        $this->assertCount(1, $payloads);
        $this->assertSame(ActionType::IMPORT, $payloads[0]['action_type']);
        $this->assertSame(240, $payloads[0]['record_count']);
        $this->assertSame('high', $payloads[0]['priority']);
    }

    // -----------------------------------------------------------------
    // Who does and does not trigger a notification
    // -----------------------------------------------------------------

    public function test_a_super_admins_own_actions_do_not_notify(): void
    {
        $this->actingAs($this->superAdmin);

        Child::factory()->create();

        $this->assertSame([], $this->payloads());
    }

    public function test_unauthenticated_writes_do_not_notify(): void
    {
        Child::factory()->create();

        $this->assertSame([], $this->payloads());
    }

    public function test_every_configured_super_admin_receives_the_notification(): void
    {
        $second = User::factory()->create();
        $second->assignRole('Super Admin');

        $this->actingAs($this->dataEntry);
        Child::factory()->create();

        $this->assertSame(1, $this->superAdmin->notifications()->count());
        $this->assertSame(1, $second->notifications()->count());
    }

    public function test_only_the_selected_super_admins_receive_notifications(): void
    {
        $excluded = User::factory()->create();
        $excluded->assignRole('Super Admin');

        $settings = app(NotificationSettings::class);
        $settings->recipient_user_ids = [$this->superAdmin->id];
        $settings->save();

        $this->actingAs($this->dataEntry);
        Child::factory()->create();

        $this->assertSame(1, $this->superAdmin->notifications()->count());
        $this->assertSame(0, $excluded->notifications()->count());
    }

    // -----------------------------------------------------------------
    // Settings switches
    // -----------------------------------------------------------------

    public function test_the_master_switch_stops_everything(): void
    {
        $settings = app(NotificationSettings::class);
        $settings->enabled = false;
        $settings->save();

        $this->actingAs($this->dataEntry);
        Child::factory()->create();

        $this->assertSame([], $this->payloads());
    }

    public function test_a_disabled_action_type_stops_only_that_action(): void
    {
        $settings = app(NotificationSettings::class);
        $settings->enabled_actions = [ActionType::UPDATE];
        $settings->save();

        $this->actingAs($this->dataEntry);

        $child = Child::factory()->create();
        $child->update(['name' => 'اسم جديد']);

        $this->assertSame([ActionType::UPDATE], array_column($this->payloads(), 'action_type'));
    }

    // -----------------------------------------------------------------
    // Grouping and bulk suppression
    // -----------------------------------------------------------------

    public function test_rapid_actions_are_grouped_into_one_delivery(): void
    {
        $this->setWindow(60);
        Queue::fake();

        $this->actingAs($this->dataEntry);

        foreach (range(1, 5) as $i) {
            Child::factory()->create(['child_id' => '10000000' . $i]);
        }

        // Five creates, one scheduled delivery.
        Queue::assertPushed(DeliverDataActionNotification::class, 1);
    }

    public function test_a_grouped_delivery_reports_the_collected_count(): void
    {
        $this->setWindow(60);

        // Only the delivery job is faked: under the sync driver a delayed job
        // runs at once, which would close the window before the next action
        // could join it. The notification itself still sends for real.
        Queue::fake([DeliverDataActionNotification::class]);

        $this->actingAs($this->dataEntry);

        foreach (range(1, 5) as $i) {
            Child::factory()->create(['child_id' => '20000000' . $i]);
        }

        $jobs = collect(Queue::pushedJobs()[DeliverDataActionNotification::class] ?? []);
        $this->assertCount(1, $jobs, 'Five creates should schedule one delivery.');

        $jobs->first()['job']->handle();

        $payloads = $this->payloads();

        $this->assertCount(1, $payloads);
        $this->assertSame(5, $payloads[0]['record_count']);
        $this->assertStringContainsString('5', $payloads[0]['body']);
        // A summary covers several records, so it must not link to one of them.
        $this->assertNull($payloads[0]['reference_url']);
    }

    public function test_different_modules_are_grouped_separately(): void
    {
        $this->setWindow(60);
        Queue::fake();

        $this->actingAs($this->dataEntry);

        Child::factory()->create();
        GroupSession::create([
            'session_date' => '2026-08-01',
            'session_group_number' => '1',
            'session_subject' => 'bf_support',
            'locality' => 'tal_al_hawa',
            'shelter_name' => 'mahabba',
            'id_number' => '123456789',
            'full_name_ar' => 'مشاركة',
            'visit_type' => 'new',
            'category' => 'grandmothers',
            'marital_status' => 'married',
            'phone_number' => '0599123456',
        ]);

        Queue::assertPushed(DeliverDataActionNotification::class, 2);
    }

    public function test_bulk_writes_can_be_suppressed_for_a_single_summary(): void
    {
        $this->actingAs($this->dataEntry);

        $written = ActionNotifier::withoutRecordNotifications(function (): int {
            foreach (range(1, 4) as $i) {
                Child::factory()->create(['child_id' => '30000000' . $i]);
            }

            return 4;
        });

        // No per-row notifications...
        $this->assertSame([], $this->payloads());

        // ...only the one summary the import announces afterwards.
        ExcelActionOccurred::dispatch('Child', ActionType::IMPORT, $this->dataEntry, $written);

        $payloads = $this->payloads();
        $this->assertCount(1, $payloads);
        $this->assertSame(4, $payloads[0]['record_count']);
    }

    public function test_suppression_is_lifted_afterwards(): void
    {
        $this->actingAs($this->dataEntry);

        ActionNotifier::withoutRecordNotifications(fn () => Child::factory()->create());

        $this->assertFalse(ActionNotifier::isSuppressed());

        Child::factory()->create();
        $this->assertCount(1, $this->payloads());
    }

    // -----------------------------------------------------------------
    // Delivery is queued, so the original request is not slowed down
    // -----------------------------------------------------------------

    public function test_delivery_is_queued_rather_than_inline(): void
    {
        Queue::fake();

        $this->actingAs($this->dataEntry);
        Child::factory()->create();

        Queue::assertPushed(DeliverDataActionNotification::class);
        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    public function test_the_notification_class_is_queueable(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new DataActionNotification([
                'action_type' => ActionType::CREATE,
                'title' => 'x',
                'body' => 'y',
                'reference_url' => null,
            ]),
        );
    }

    // -----------------------------------------------------------------
    // Pages
    // -----------------------------------------------------------------

    public function test_the_pages_are_restricted_to_the_notifications_permission(): void
    {
        $this->actingAs($this->dataEntry);
        $this->assertFalse(NotificationSettingsPage::canAccess());
        $this->assertFalse(NotificationLogPage::canAccess());

        $this->actingAs($this->superAdmin);
        $this->assertTrue(NotificationSettingsPage::canAccess());
        $this->assertTrue(NotificationLogPage::canAccess());
    }

    public function test_the_settings_page_saves_its_switches(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(NotificationSettingsPage::class)
            ->assertSuccessful()
            ->fillForm([
                'enabled' => false,
                'enabled_actions' => [ActionType::CREATE, ActionType::IMPORT],
                'recipient_user_ids' => [$this->superAdmin->id],
                'group_window_seconds' => 120,
            ])
            ->callAction('save');

        $settings = app(NotificationSettings::class);

        $this->assertFalse($settings->enabled);
        $this->assertSame([ActionType::CREATE, ActionType::IMPORT], $settings->enabled_actions);
        $this->assertSame([$this->superAdmin->id], $settings->recipient_user_ids);
        $this->assertSame(120, $settings->group_window_seconds);
    }

    /**
     * `notify_self_actions` was added to the settings class after its migration
     * had already run, so nothing was stored for it. Reads fell back to the
     * class default, but spatie counts a default-loaded property as missing
     * when saving, which broke the whole page with MissingSettings.
     */
    public function test_the_settings_page_round_trips_notify_self_actions(): void
    {
        $this->actingAs($this->superAdmin);

        // The stored default is what the page shows on first load.
        $this->assertFalse(app(NotificationSettings::class)->notify_self_actions);

        Livewire::test(NotificationSettingsPage::class)
            ->assertSuccessful()
            ->assertFormSet(['notify_self_actions' => false])
            ->fillForm(['notify_self_actions' => true])
            ->callAction('save')
            ->assertHasNoErrors();

        $this->assertTrue(app(NotificationSettings::class)->notify_self_actions);

        // Reopening the page reflects the saved value rather than the default.
        Livewire::test(NotificationSettingsPage::class)
            ->assertFormSet(['notify_self_actions' => true]);

        // Saving again from that state must keep working and stay true.
        Livewire::test(NotificationSettingsPage::class)
            ->callAction('save')
            ->assertHasNoErrors();

        $this->assertTrue(app(NotificationSettings::class)->notify_self_actions);
    }

    public function test_saving_notify_self_actions_leaves_the_other_settings_alone(): void
    {
        $this->actingAs($this->superAdmin);

        $before = app(NotificationSettings::class);
        $enabled = $before->enabled;
        $actions = $before->enabled_actions;
        $recipients = $before->recipient_user_ids;
        $window = $before->group_window_seconds;

        Livewire::test(NotificationSettingsPage::class)
            ->fillForm(['notify_self_actions' => true])
            ->callAction('save');

        $after = app(NotificationSettings::class);

        $this->assertSame($enabled, $after->enabled);
        $this->assertSame($actions, $after->enabled_actions);
        $this->assertSame($recipients, $after->recipient_user_ids);
        $this->assertSame($window, $after->group_window_seconds);
    }

    public function test_the_log_page_lists_and_filters_notifications(): void
    {
        $this->actingAs($this->dataEntry);
        Child::factory()->create();
        ExcelActionOccurred::dispatch('GroupSession', ActionType::EXPORT, $this->dataEntry);

        $this->actingAs($this->superAdmin);

        $records = NotificationLogPage::baseQuery()->get();
        $this->assertCount(2, $records);

        Livewire::test(NotificationLogPage::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($records)
            ->filterTable('action_type', ActionType::EXPORT)
            ->assertCanSeeTableRecords($records->filter(
                fn (DatabaseNotification $n): bool => $n->data['action_type'] === ActionType::EXPORT,
            ))
            ->assertCanNotSeeTableRecords($records->filter(
                fn (DatabaseNotification $n): bool => $n->data['action_type'] === ActionType::CREATE,
            ));
    }

    public function test_the_log_page_exports(): void
    {
        $this->actingAs($this->dataEntry);
        Child::factory()->create();

        $this->actingAs($this->superAdmin);

        $export = new \App\Exports\NotificationLogExport(NotificationLogPage::baseQuery());

        $this->assertCount(10, $export->headings());

        $row = $export->map(NotificationLogPage::baseQuery()->first());

        $this->assertSame(ActionType::title(ActionType::CREATE), $row[1]);
        $this->assertSame('الأطفال', $row[2]);
        $this->assertSame('أحمد', $row[3]);
    }
}
