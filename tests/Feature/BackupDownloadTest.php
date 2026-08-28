<?php

namespace Tests\Feature;

use App\Filament\Pages\Backups;
use Livewire\Livewire;
use Tests\TestCase;

class BackupDownloadTest extends TestCase
{
    public function test_sql_backup_creation_and_latest_backup_retrieval(): void
    {
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
}
