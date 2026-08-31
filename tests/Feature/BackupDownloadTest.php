<?php

namespace Tests\Feature;

use App\Filament\Pages\Backups;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BackupDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_sql_backup_creation_and_latest_backup_retrieval(): void
    {
        $this->actingAsRole('Super Admin');

        $backupPage = new Backups();
        $filePath = $backupPage->createSqlBackup();

        $this->assertFileExists($filePath);
        $this->assertStringEndsWith('.sql', $filePath);

        $latestBackup = $backupPage->getLatestBackup();
        $this->assertNotNull($latestBackup);
        $this->assertEquals(basename($filePath), $latestBackup['name']);

        // Clean up test backup file
        $backupPage->deleteBackup($filePath);
        $this->assertFileDoesNotExist($filePath);
    }

    // -----------------------------------------------------------------
    // Server-side guards
    // -----------------------------------------------------------------

    public function test_creating_a_backup_without_the_permission_is_refused(): void
    {
        $this->actingAsRole('Data Entry');

        $this->expectException(HttpException::class);

        (new Backups())->createSqlBackup();
    }

    public function test_downloading_without_the_permission_is_refused(): void
    {
        $this->actingAsRole('Super Admin');
        $backupPage = new Backups();
        $filePath = $backupPage->createSqlBackup();

        $this->actingAsRole('Data Entry');

        try {
            $this->expectException(HttpException::class);
            (new Backups())->downloadBackup($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /**
     * A path the page never listed - such as the environment file - must not be
     * served just because it exists on disk.
     */
    public function test_a_path_outside_the_backup_listing_cannot_be_downloaded(): void
    {
        $this->actingAsRole('Super Admin');

        $this->expectException(HttpException::class);

        (new Backups())->downloadBackup(base_path('.env.example'));
    }

    public function test_a_path_outside_the_backup_listing_cannot_be_deleted(): void
    {
        $this->actingAsRole('Super Admin');

        $target = storage_path('app/not-a-backup.txt');
        file_put_contents($target, 'keep me');

        try {
            $this->assertFalse((new Backups())->deleteBackup($target));
            $this->assertFileExists($target);
        } finally {
            @unlink($target);
        }
    }

    public function test_deleting_without_the_permission_is_refused(): void
    {
        $this->actingAsRole('Super Admin');
        $backupPage = new Backups();
        $filePath = $backupPage->createSqlBackup();

        $this->actingAsRole('Data Entry');

        try {
            $this->expectException(HttpException::class);
            (new Backups())->deleteBackup($filePath);
        } finally {
            @unlink($filePath);
        }
    }
}
