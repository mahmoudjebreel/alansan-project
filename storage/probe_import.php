<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Imports\ImportDefinition;
use App\Support\ImportSchema;

$def = ImportDefinition::get('children');
$schema = new ImportSchema($def);

$cases = [
    ['is_enrolled_bsfp', 'Yes'], ['is_enrolled_bsfp', 'نعم'], ['is_enrolled_bsfp', 'لا'],
    ['is_enrolled_bsfp', false], ['is_enrolled_bsfp', true], ['is_enrolled_bsfp', 0], ['is_enrolled_bsfp', 1],
    ['is_enrolled_bsfp', "\u{00A0}"], ['is_enrolled_bsfp', "  "], ['is_enrolled_bsfp', "\u{200B}"],
    ['is_enrolled_bsfp', '-'], ['is_enrolled_bsfp', 'N/A'], ['is_enrolled_bsfp', 'لا يوجد'],
    ['income_source', 'Government'], ['income_source', 'UNRWA'], ['income_source', 'Other'],
    ['income_source', 'حكومي'], ['income_source', 'وكالة'], ['income_source', 'أخرى'],
    ['income_source', 'government'], ['income_source', 'Governmental'],
    ['disability_cause', 'War'], ['disability_cause', 'الحرب'], ['disability_cause', 'Other'], ['disability_cause', 'أخرى'],
    ['mother_date_of_birth', ''], ['mother_date_of_birth', '   '], ['mother_date_of_birth', "\u{00A0}"],
    ['mother_date_of_birth', 0], ['mother_date_of_birth', '0'], ['mother_date_of_birth', 0.0],
    ['mother_date_of_birth', '-'], ['mother_date_of_birth', 'N/A'], ['mother_date_of_birth', 45000],
    ['mother_date_of_birth', '1990-05-04'], ['mother_date_of_birth', "\u{200E}"],
];

echo "OPTIONS income_source: "; var_export($schema->optionsFor('income_source')); echo "\n";
echo "OPTIONS disability_cause: "; var_export($schema->optionsFor('disability_cause')); echo "\n";
echo "OPTIONS is_enrolled_bsfp: "; var_export($schema->optionsFor('is_enrolled_bsfp')); echo "\n";
echo "BOOLEAN fields contain income_source? "; var_export(in_array('income_source', $def->booleanFields(), true)); echo "\n";
echo "ENUM fields contain income_source? "; var_export(in_array('income_source', $def->enumFields(), true)); echo "\n";
echo "ENUM contains disability_cause? "; var_export(in_array('disability_cause', $def->enumFields(), true)); echo "\n";
echo "dateReader for children: "; var_export($def->dateReader); echo "\n\n";

foreach ($cases as [$f, $v]) {
    $r = $schema->castValue($f, $v);
    printf("%-24s %-18s => %s %s\n",
        $f,
        json_encode($v, JSON_UNESCAPED_UNICODE),
        $r['ok'] ? 'OK' : 'FAIL',
        $r['ok'] ? json_encode(is_object($r['value']) ? (string) $r['value'] : $r['value'], JSON_UNESCAPED_UNICODE) : $r['message']
    );
}
