<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_creating_child_sends_notification_to_super_admin(): void
    {
        $superAdmin = User::factory()->create(['name' => 'Super Admin User']);
        $superAdmin->assignRole('Super Admin');

        $dataEntry = User::factory()->create(['name' => 'Data Entry User']);
        $dataEntry->assignRole('Data Entry');

        $this->actingAs($dataEntry);

        $child = Child::factory()->create([
            'name' => 'Child Test',
            'child_id' => 'CH-1001',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $superAdmin->id,
            'notifiable_type' => User::class,
        ]);

        $notification = $superAdmin->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('إضافة سجل جديد', $notification->data['title'] ?? '');
    }

    public function test_deleting_child_sends_notification_to_super_admin(): void
    {
        $superAdmin = User::factory()->create(['name' => 'Super Admin User']);
        $superAdmin->assignRole('Super Admin');

        $admin = User::factory()->create(['name' => 'Admin User']);
        $admin->assignRole('Admin');

        $child = Child::factory()->create();

        $this->actingAs($admin);
        $child->delete();

        $notifications = $superAdmin->notifications()->get();
        $this->assertTrue($notifications->count() >= 1);

        $latestNotif = $notifications->last();
        $this->assertStringContainsString('حذف سجل', $latestNotif->data['title'] ?? '');
    }
}
