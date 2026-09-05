<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Support\ImportSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Tests\TestCase;

class ZzIcTest extends TestCase
{
    use RefreshDatabase;

    public function test_find(): void
    {
        $def = ImportDefinition::get('individual_counseling');
        $schema = new ImportSchema($def);

        foreach (glob(getenv('USERPROFILE') . '/Downloads/*.xlsx') as $path) {
            try {
                $reader = IOFactory::createReaderForFile($path);
                $reader->setReadDataOnly(true);
                $reader->setReadFilter(new class implements IReadFilter {
                    public function readCell($col, $row, $ws = ''): bool { return $row <= 1; }
                });
                $ss = $reader->load($path);
            } catch (\Throwable) { continue; }

            foreach ($ss->getSheetNames() as $si => $name) {
                $head = $ss->getSheet($si)->toArray(null, true, false, false)[0] ?? [];
                $seen = [];
                foreach ($head as $h) {
                    $r = $schema->resolveHeading(is_scalar($h) ? (string) $h : null);
                    if ($r && ($r['type'] ?? '') === 'field') { $seen[] = $r['field']; }
                }
                if (count($seen) < 8) { continue; }
                fwrite(STDERR, sprintf("%-45s [%-25s] matched=%d missing=%s\n",
                    mb_substr(basename($path), 0, 44), mb_substr($name, 0, 24), count($seen),
                    implode(',', array_diff($schema->requiredFields(), $seen)) ?: '-'));
            }
        }
        $this->assertTrue(true);
    }
}
