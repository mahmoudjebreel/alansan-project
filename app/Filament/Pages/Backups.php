<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Backups extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'النسخ الاحتياطي';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $title = 'إدارة النسخ الاحتياطي';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.backups';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('backup.manage') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_backup')
                ->label('إنشاء وتحميل نسخة SQL مباشرة')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('إنشاء وتنزيل نسخة احتياطية')
                ->modalDescription('سيتم إنشاء نسخة احتياطية من قاعدة البيانات بصيغة .sql وتنزيلها تلقائياً على جهازك. هل تريد المتابعة؟')
                ->modalSubmitActionLabel('نعم، أنشئ ونزّل النسخة')
                ->action(function (): ?BinaryFileResponse {
                    try {
                        $filePath = $this->createSqlBackup();

                        Notification::make()
                            ->title('تم إنشاء النسخة الاحتياطية بنجاح')
                            ->body('جاري تنزيل الملف على جهازك...')
                            ->success()
                            ->send();

                        return response()->download($filePath, basename($filePath), [
                            'Content-Type' => 'text/x-sql',
                        ]);
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('فشل إنشاء النسخة الاحتياطية')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return null;
                    }
                }),
        ];
    }

    /**
     * Create a pure SQL dump file and store it in backups directory.
     */
    public function createSqlBackup(): string
    {
        // Public Livewire methods are callable straight from the browser, so
        // the page-level canAccess() check is repeated here: a full SQL dump of
        // every patient record must never be reachable without backup.manage.
        abort_unless(static::canAccess(), 403);

        $backupDir = storage_path('app/backups');
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = 'backup-' . date('Y-m-d-H-i-s') . '.sql';
        $fullPath = $backupDir . '/' . $filename;

        $sqlContent = $this->dumpDatabaseToSql();
        file_put_contents($fullPath, $sqlContent);

        return $fullPath;
    }

    /**
     * Get only the latest single backup file details.
     */
    public function getLatestBackup(): ?array
    {
        $backups = $this->getAllBackupFiles();

        return $backups[0] ?? null;
    }

    /**
     * Fetch all backup files sorted by date descending.
     */
    public function getAllBackupFiles(): array
    {
        $backupDir = storage_path('app/backups');
        $files = [];

        // Scan local backup storage
        if (file_exists($backupDir)) {
            $scan = scandir($backupDir);
            foreach ($scan as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                if (str_ends_with($file, '.sql') || str_ends_with($file, '.zip')) {
                    $path = $backupDir . '/' . $file;
                    $files[] = [
                        'path' => $path,
                        'name' => $file,
                        'size' => $this->formatBytes(filesize($path)),
                        'date' => date('Y-m-d H:i:s', filemtime($path)),
                        'timestamp' => filemtime($path),
                    ];
                }
            }
        }

        // Also scan spatie backup disk if configured
        try {
            $disk = Storage::disk(config('backup.backup.destination.disks')[0] ?? 'local');
            $backupName = config('backup.backup.name') ?? config('app.name');
            $backupFiles = $disk->files($backupName);

            foreach ($backupFiles as $file) {
                if (str_ends_with($file, '.zip') || str_ends_with($file, '.sql')) {
                    $mtime = $disk->lastModified($file);
                    $files[] = [
                        'path' => $file,
                        'name' => basename($file),
                        'size' => $this->formatBytes($disk->size($file)),
                        'date' => date('Y-m-d H:i:s', $mtime),
                        'timestamp' => $mtime,
                        'is_disk' => true,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Directory or disk may not exist
        }

        usort($files, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $files;
    }

    /**
     * Download specific backup file.
     */
    public function downloadBackup(string $path): BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);

        // $path arrives from the browser. Only a path this page itself listed
        // may be served: without this, any string the client sends is handed
        // straight to response()->download(), which reads any file the web
        // user can reach (.env included).
        $file = $this->resolveListedBackup($path);

        abort_if($file === null, 404);

        if (! ($file['is_disk'] ?? false)) {
            return response()->download($file['path']);
        }

        $disk = Storage::disk(config('backup.backup.destination.disks')[0] ?? 'local');

        return response()->download($disk->path($file['path']));
    }

    /**
     * Delete a backup file. Returns true on success so the front-end can
     * show the SweetAlert2 toast; feedback is handled entirely client-side.
     */
    public function deleteBackup(string $path): bool
    {
        abort_unless(static::canAccess(), 403);

        // Same guard as downloadBackup(): an unvalidated $path here is an
        // arbitrary unlink() on the server, not just a backup being removed.
        $file = $this->resolveListedBackup($path);

        if ($file === null) {
            return false;
        }

        if (! ($file['is_disk'] ?? false)) {
            return unlink($file['path']);
        }

        try {
            $disk = Storage::disk(config('backup.backup.destination.disks')[0] ?? 'local');
            $disk->delete($file['path']);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Match a client-supplied path against the backups this page actually
     * lists, and return that entry. Anything else is refused.
     *
     * Allow-listing rather than sanitising: the set of legitimate targets is
     * already known exactly (getAllBackupFiles()), so there is no traversal
     * pattern left to get wrong.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveListedBackup(string $path): ?array
    {
        foreach ($this->getAllBackupFiles() as $file) {
            if ($file['path'] === $path) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Generate raw SQL export content for current database connection.
     */
    protected function dumpDatabaseToSql(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $sql = "-- Database Backup Export (.sql)\n";
        $sql .= "-- App: " . config('app.name') . "\n";
        $sql .= "-- Date & Time: " . date('Y-m-d H:i:s') . "\n\n";

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $tables = $connection->select('SHOW TABLES');
            $dbName = $connection->getDatabaseName();
            $tableKey = "Tables_in_{$dbName}";

            foreach ($tables as $tableObj) {
                $table = $tableObj->$tableKey ?? current((array)$tableObj);
                $createTable = $connection->select("SHOW CREATE TABLE `{$table}`");
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $createTable[0]->{'Create Table'} . ";\n\n";

                $rows = $connection->table($table)->get();
                foreach ($rows as $row) {
                    $values = array_map(function ($val) use ($connection) {
                        if (is_null($val)) return 'NULL';
                        return $connection->getPdo()->quote((string)$val);
                    }, (array)$row);
                    $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
                }
                $sql .= "\n\n";
            }
        } else {
            // SQLite or default fallback
            $tables = $connection->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            foreach ($tables as $tableObj) {
                $table = $tableObj->name;
                $createTable = $connection->select("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
                if (!empty($createTable[0]->sql)) {
                    $sql .= "DROP TABLE IF EXISTS \"{$table}\";\n";
                    $sql .= $createTable[0]->sql . ";\n\n";

                    $rows = $connection->table($table)->get();
                    foreach ($rows as $row) {
                        $values = array_map(function ($val) use ($connection) {
                            if (is_null($val)) return 'NULL';
                            return $connection->getPdo()->quote((string)$val);
                        }, (array)$row);
                        $sql .= "INSERT INTO \"{$table}\" VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $sql .= "\n\n";
                }
            }
        }

        return $sql;
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
