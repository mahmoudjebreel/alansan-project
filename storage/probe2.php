<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Imports\ImportDefinition;
use App\Support\ImportSchema;

$schema = new ImportSchema(ImportDefinition::get('children'));

$cases = [
    ['income_source', 'وكالة أونروا'], ['income_source', 'الوكالة'], ['income_source', 'اونروا'],
    ['income_source', 'UNRWA Agency'], ['income_source', 'Agency'], ['income_source', 'غير ذلك'],
    ['income_source', 'حكومية'], ['income_source', 'الحكومة'], ['income_source', 'اخرى'],
    ['income_source', 'other '],
    ['disability_cause', 'غير ذلك'], ['disability_cause', 'اخرى'], ['disability_cause', 'الحرب على غزة'],
    ['disability_cause', 'إصابة حرب'], ['disability_cause', 'حرب'],
    ['is_enrolled_bsfp', 'TRUE'], ['is_enrolled_bsfp', 'FALSE'], ['is_enrolled_bsfp', 'نعم '],
    ['is_enrolled_bsfp', 'no'], ['is_enrolled_bsfp', '1.0'], ['is_enrolled_bsfp', 1.0], ['is_enrolled_bsfp', 0.0],
    ['has_released_children', false], ['has_unaccompanied_children', false], ['has_injured_after_oct7', false],
];

foreach ($cases as [$f, $v]) {
    $r = $schema->castValue($f, $v);
    printf("%-30s %-22s => %s %s\n", $f, json_encode($v, JSON_UNESCAPED_UNICODE),
        $r['ok'] ? 'OK  ' : 'FAIL', $r['ok'] ? json_encode($r['value'], JSON_UNESCAPED_UNICODE) : mb_substr($r['message'], 0, 80));
}
