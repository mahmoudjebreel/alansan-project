<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Support\ImportSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZzProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe(): void
    {
        $def = ImportDefinition::get('children');
        $schema = new ImportSchema($def);

        echo "\nBOOLEAN FIELDS: " . implode(', ', $def->booleanFields()) . "\n";
        echo "ENUM FIELDS: " . implode(', ', $def->enumFields()) . "\n";

        foreach (['income_source', 'disability_cause', 'is_enrolled_bsfp', 'has_unaccompanied_children', 'has_released_children', 'has_injured_after_oct7'] as $f) {
            $o = $schema->optionsFor($f);
            echo "OPTIONS[$f] = " . ($o === null ? 'NULL' : json_encode($o, JSON_UNESCAPED_UNICODE)) . "\n";
        }

        $cases = [
            ['income_source', 'Government'],
            ['income_source', 'UNRWA'],
            ['income_source', 'Other'],
            ['income_source', 'حكومي'],
            ['income_source', 'وكالة'],
            ['income_source', 'أخرى'],
            ['disability_cause', 'War'],
            ['disability_cause', 'Other'],
            ['disability_cause', 'الحرب'],
            ['is_enrolled_bsfp', 'Yes'],
            ['is_enrolled_bsfp', 'No'],
            ['is_enrolled_bsfp', 'نعم'],
            ['is_enrolled_bsfp', 'لا'],
            ['is_enrolled_bsfp', 'Y'],
            ['is_enrolled_bsfp', 'N'],
            ['is_enrolled_bsfp', 0],
            ['is_enrolled_bsfp', 1],
            ['is_enrolled_bsfp', '-'],
            ['is_enrolled_bsfp', 'لايوجد'],
            ['has_unaccompanied_children', 'نعم'],
            ['has_released_children', 'لا'],
            ['mother_date_of_birth', ''],
            ['mother_date_of_birth', ' '],
            ['mother_date_of_birth', "\xC2\xA0"],
            ['mother_date_of_birth', '-'],
            ['mother_date_of_birth', 0],
            ['mother_date_of_birth', '0'],
            ['mother_date_of_birth', '00/00/0000'],
            ['mother_date_of_birth', 'N/A'],
            ['mother_date_of_birth', '1990-05-04'],
            ['mother_date_of_birth', '12/7/1990'],
        ];

        foreach ($cases as [$f, $v]) {
            $r = $schema->castValue($f, $v);
            $shown = is_string($v) ? '"' . $v . '"(len' . strlen($v) . ')' : var_export($v, true);
            echo sprintf(
                "CAST %-28s %-22s => %s %s\n",
                $f,
                $shown,
                $r['ok'] ? 'OK' : 'FAIL',
                $r['ok'] ? json_encode(is_object($r['value']) ? (string) $r['value'] : $r['value'], JSON_UNESCAPED_UNICODE) : $r['message'],
            );
        }

        $this->assertTrue(true);
    }
}
