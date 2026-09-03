<?php

/**
 * Read-only diagnostic for the Pregnant/Lactating import date columns.
 *
 * Reads an uploaded workbook exactly the way the importer reads it, then prints
 * the literal value of every date cell the importer refuses: its PHP type, its
 * length, its raw bytes, and what each layer of the pipeline does with it.
 *
 * Writes nothing and touches no database row.
 *
 * Usage:  php diagnose_dates.php /full/path/to/the-file.xlsx
 *
 * Delete this file once the cause is known.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Imports\ImportDefinition;
use App\Support\ImportSchema;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Facades\Excel;

$path = $argv[1] ?? null;

if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "Usage: php diagnose_dates.php /full/path/to/file.xlsx\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 0. Which build of the code is actually running here.
// ---------------------------------------------------------------------------
$datesClass = 'App\Support\Import\PregnantWomanImportDates';
$importSrc = @file_get_contents(__DIR__ . '/app/Imports/PregnantWomenImport.php') ?: '';
$schemaSrc = @file_get_contents(__DIR__ . '/app/Support/ImportSchema.php') ?: '';

echo "=== deployed code ===\n";
printf("  PregnantWomanImportDates class loadable : %s\n", class_exists($datesClass) ? 'YES' : 'NO  <-- missing');
printf("  PregnantWomenImport calls it            : %s\n", str_contains($importSrc, 'PregnantWomanImportDates') ? 'YES' : 'NO  <-- old file');
printf("  castDate() handles DateTimeInterface    : %s\n", str_contains($schemaSrc, 'DateTimeInterface') ? 'YES' : 'NO  <-- old file');
printf("  newborn_dob listed in the dates class   : %s\n", class_exists($datesClass) && $datesClass::handles('newborn_dob') ? 'YES' : 'NO  <-- name mismatch');
echo "\n";

// ---------------------------------------------------------------------------
// 1. Read the sheet exactly as AbstractTableImport reads it.
// ---------------------------------------------------------------------------
$definition = ImportDefinition::get('pregnant');
$schema = new ImportSchema($definition);

$collector = new class implements ToCollection
{
    public array $sheet = [];

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $this->sheet[] = collect($row)->values()->all();
        }
    }
};

Excel::import($collector, $path);

$sheet = $collector->sheet;

if ($sheet === []) {
    fwrite(STDERR, "The sheet read as empty.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Locate the date columns through the importer's own heading resolver.
// ---------------------------------------------------------------------------
$dateColumns = [];

foreach ($sheet[0] as $index => $heading) {
    $resolved = $schema->resolveHeading(is_scalar($heading) ? (string) $heading : null);

    if ($resolved === null || ($resolved['type'] ?? null) !== 'field') {
        continue;
    }

    if (in_array($resolved['field'], ['newborn_dob', 'date_of_reporting', 'date_of_birth'], true)) {
        $dateColumns[$resolved['field']] = $index;
    }
}

echo "=== date columns found in the file ===\n";
foreach (['newborn_dob', 'date_of_reporting', 'date_of_birth'] as $f) {
    printf("  %-18s : %s\n", $f, isset($dateColumns[$f]) ? 'column index ' . $dateColumns[$f] : 'NOT PRESENT IN FILE');
}
echo "\n";

// ---------------------------------------------------------------------------
// 3. Replay every data row through the real pipeline and report the refusals.
// ---------------------------------------------------------------------------
$describe = static function (mixed $v): string {
    if (is_object($v)) {
        return sprintf(
            'object(%s)%s',
            get_class($v),
            $v instanceof DateTimeInterface ? ' = ' . $v->format('Y-m-d H:i:s') : '',
        );
    }

    if (is_string($v)) {
        return sprintf(
            'string(%d) "%s" hex=%s',
            strlen($v),
            $v,
            strtoupper(bin2hex(substr($v, 0, 40))),
        );
    }

    return gettype($v) . '(' . var_export($v, true) . ')';
};

$failures = 0;
$typeTally = [];

foreach ($sheet as $i => $row) {
    if ($i === 0) {
        continue; // headings
    }

    $rowNumber = $i + 1; // 1-based sheet row, matching the error messages

    foreach ($dateColumns as $field => $index) {
        $raw = $row[$index] ?? null;
        $value = is_string($raw) ? trim($raw) : $raw;

        // Layer 1, exactly as PregnantWomenImport::normaliseValue() applies it.
        if (class_exists($datesClass)) {
            $value = $datesClass::normalise($field, $value);
        }

        $n = App\Support\Import\PregnantWomanImportSynonyms::normalise($field, $value);

        if (! $n['ok']) {
            continue;
        }

        // Layer 2.
        $cast = $schema->castValue($field, $n['value']);

        if ($cast['ok']) {
            continue;
        }

        $failures++;
        $typeTally[gettype($raw) . (is_object($raw) ? ':' . get_class($raw) : '')] ??= 0;
        $typeTally[gettype($raw) . (is_object($raw) ? ':' . get_class($raw) : '')]++;

        if ($failures <= 40) {
            printf("Row %-6d %-18s RAW %s\n", $rowNumber, $field, $describe($raw));
        }
    }
}

echo "\n=== summary ===\n";
printf("  refused date cells : %d\n", $failures);

foreach ($typeTally as $type => $count) {
    printf("  %-30s : %d\n", $type, $count);
}

if ($failures === 0) {
    echo "  No date cell is refused by the code running here.\n";
}
