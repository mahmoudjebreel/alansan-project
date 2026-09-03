<?php

namespace App\Support\Import;

/**
 * Accepted spellings for every Select column of the Pregnant / Lactating Women
 * import, in Arabic and in English, mapped to the single internal value the
 * database actually stores.
 *
 * This is the one place to edit when a new option is added to that module's
 * form: add the option's stored value, its Arabic label and its English label
 * to the field's `values` map, and add the two labels to `display` so the
 * rejection message keeps listing what a file may contain.
 *
 * Deliberate properties of this layer:
 *
 *  - One map per field, never a single shared table. "متابعة" means follow_up
 *    only under visit_type; nothing here can leak into another column.
 *  - Matching is literal. A cell is trimmed, its runs of whitespace squeezed to
 *    one space and its letters lowercased, and the result must equal a key
 *    exactly. There is no fuzzy matching and no nearest-option guessing, so
 *    "حاملة" and "Pregnent" are refused rather than quietly read as "حامل".
 *  - Only the reading of a file changes. Stored values, the export, the manual
 *    form and existing records are all untouched.
 */
final class PregnantWomanImportSynonyms
{
    /**
     * field => [
     *   'values'  => accepted spelling => stored value,
     *   'display' => the spellings named back to the uploader on a rejection,
     * ]
     *
     * Keys are matched case-insensitively, so each spelling is written once in
     * its natural casing.
     *
     * @var array<string, array{values: array<string, string>, display: array<int, string>}>
     */
    private const FIELDS = [
        // ------------------------------------------------------------------
        // حامل / مرضع — stored as the enum values pregnant | lactating |
        // pregnant_lactating (pregnant and breastfeeding at the same time).
        // ------------------------------------------------------------------
        'status_type' => [
            'values' => [
                'حامل' => 'pregnant',
                'Pregnant' => 'pregnant',
                // The one-letter codes the field workbooks are actually filled
                // in with. Unambiguous within this column: no other spelling
                // here is a single P or a single L.
                'P' => 'pregnant',

                'مرضع' => 'lactating',
                'مرضعة' => 'lactating',
                'مرضعه' => 'lactating',
                'Breastfeeding' => 'lactating',
                'Breast Feeding' => 'lactating',
                'Breast-feeding' => 'lactating',
                'Lactating' => 'lactating',
                'L' => 'lactating',

                'حامل + مرضع' => 'pregnant_lactating',
                'حامل ومرضع' => 'pregnant_lactating',
                'حامل و مرضع' => 'pregnant_lactating',
                'حامل + مرضعة' => 'pregnant_lactating',
                'حامل + مرضعه' => 'pregnant_lactating',
                'حامل/مرضع' => 'pregnant_lactating',
                'Pregnant + Breastfeeding' => 'pregnant_lactating',
                'Pregnant and Breastfeeding' => 'pregnant_lactating',
                'Pregnant + Breast Feeding' => 'pregnant_lactating',
                'Pregnant + Lactating' => 'pregnant_lactating',
                'Pregnant and Lactating' => 'pregnant_lactating',
                'Pregnant/Lactating' => 'pregnant_lactating',
                'P+L' => 'pregnant_lactating',
                'P/L' => 'pregnant_lactating',
                'PL' => 'pregnant_lactating',
                // The same code with the two letters the other way round, which
                // the workbooks use just as often.
                'L+P' => 'pregnant_lactating',
                'L/P' => 'pregnant_lactating',
                'LP' => 'pregnant_lactating',
                'pregnant_lactating' => 'pregnant_lactating',
            ],
            'display' => [
                'حامل', 'مرضع', 'حامل + مرضع',
                'Pregnant', 'Breastfeeding', 'Lactating', 'Pregnant + Breastfeeding',
            ],
        ],

        // ------------------------------------------------------------------
        // نوع الزيارة — stored as the enum values new | follow_up.
        // ------------------------------------------------------------------
        'visit_type' => [
            'values' => [
                'جديد' => 'new',
                'جديدة' => 'new',
                'جديده' => 'new',
                'New' => 'new',

                'متابعة' => 'follow_up',
                'متابعه' => 'follow_up',
                'Follow-up' => 'follow_up',
                'Follow up' => 'follow_up',
                'Followup' => 'follow_up',
                'follow_up' => 'follow_up',
            ],
            'display' => ['جديد', 'متابعة', 'New', 'Follow-up'],
        ],

        // ------------------------------------------------------------------
        // الحي — stored as the English key the form offers.
        // ------------------------------------------------------------------
        'neighbourhood' => [
            'values' => [
                'الشاطئ' => 'El Shatee',
                'الشاطىء' => 'El Shatee',
                'الشاطي' => 'El Shatee',
                'El Shatee' => 'El Shatee',
                'Al Shatee' => 'El Shatee',

                'النفَق' => 'El Nafaq',
                'النفق' => 'El Nafaq',
                'El Nafaq' => 'El Nafaq',
                'Al Nafaq' => 'El Nafaq',

                'الصفطاوي' => 'El Saftawi',
                'El Saftawi' => 'El Saftawi',
                'Al Saftawi' => 'El Saftawi',

                'تل الهوى' => 'Tal EalHawa',
                'تل الهوا' => 'Tal EalHawa',
                'Tal Al Hawa' => 'Tal EalHawa',
                'Tal El Hawa' => 'Tal EalHawa',
                'Tal EalHawa' => 'Tal EalHawa',
            ],
            'display' => [
                'الشاطئ', 'النفَق', 'الصفطاوي', 'تل الهوى',
                'El Shatee', 'El Nafaq', 'El Saftawi', 'Tal Al Hawa',
            ],
        ],

        // ------------------------------------------------------------------
        // نوع الموقع — stored as the English site name the form offers.
        // ------------------------------------------------------------------
        'type_of_site' => [
            'values' => [
                'مخيم السلام' => 'El Salam Camp',
                'السلام' => 'El Salam Camp',
                'El Salam Camp' => 'El Salam Camp',
                'Al Salam Camp' => 'El Salam Camp',

                'مخيم مصعب' => 'Mossab Camp',
                'مصعب' => 'Mossab Camp',
                'Mossab Camp' => 'Mossab Camp',
                'Mosaab Camp' => 'Mossab Camp',
                'Musab Camp' => 'Mossab Camp',

                'مخيم المحبة' => 'Mahabba Camp',
                'المحبة' => 'Mahabba Camp',
                'المحبه' => 'Mahabba Camp',
                'محبة' => 'Mahabba Camp',
                'محبه' => 'Mahabba Camp',
                'Mahabba' => 'Mahabba Camp',
                'Mahabba Camp' => 'Mahabba Camp',

                'مخيم القوقا' => 'El Qoqa',
                'القوقا' => 'El Qoqa',
                'El Qoqa' => 'El Qoqa',
                'Al Qoqa' => 'El Qoqa',
            ],
            'display' => [
                'مخيم السلام', 'مخيم مصعب', 'المحبة', 'القوقا',
                'El Salam Camp', 'Mossab Camp', 'Mahabba', 'El Qoqa',
            ],
        ],

        // ------------------------------------------------------------------
        // الحالة الاجتماعية — stored in Arabic, which is what the form offers.
        // ------------------------------------------------------------------
        'status' => [
            'values' => [
                'متزوجة' => 'متزوجة',
                'متزوجه' => 'متزوجة',
                'Married' => 'متزوجة',

                'أرملة' => 'أرملة',
                'أرمله' => 'أرملة',
                'ارملة' => 'أرملة',
                'ارمله' => 'أرملة',
                'Widow' => 'أرملة',
                'Widowed' => 'أرملة',

                'مطلقة' => 'مطلقة',
                'مطلقه' => 'مطلقة',
                'Divorced' => 'مطلقة',

                'منفصلة' => 'منفصلة',
                'منفصله' => 'منفصلة',
                'Separated' => 'منفصلة',

                'الزوج مفقود' => 'الزوج مفقود',
                'زوج مفقود' => 'الزوج مفقود',
                'Husband Missing' => 'الزوج مفقود',
                'Missing Husband' => 'الزوج مفقود',

                'مهجورة' => 'مهجورة',
                'مهجوره' => 'مهجورة',
                'Abandoned' => 'مهجورة',

                // Stored without the shadda, the way the other six options and
                // the workbooks themselves are written; the shadda spelling is
                // accepted on the way in.
                'معلقة' => 'معلقة',
                'معلّقة' => 'معلقة',
                'معلقه' => 'معلقة',
                'معلّقه' => 'معلقة',
                'Pending' => 'معلقة',
            ],
            'display' => [
                'متزوجة', 'أرملة', 'مطلقة', 'منفصلة', 'الزوج مفقود', 'مهجورة', 'معلقة',
                'Married', 'Widowed', 'Divorced', 'Separated', 'Husband Missing', 'Abandoned', 'Pending',
            ],
        ],
    ];

    /**
     * The نعم / لا dropdowns. They are Select fields on the same form, so they
     * are normalised here too; the canonical 1/0 is a spelling the shared
     * boolean cast already understands, so nothing downstream changes.
     *
     * @var array<string, string>
     */
    private const BOOLEAN_VALUES = [
        'نعم' => '1',
        'Yes' => '1',
        'Y' => '1',
        'True' => '1',
        '1' => '1',

        'لا' => '0',
        'No' => '0',
        'N' => '0',
        'False' => '0',
        '0' => '0',
    ];

    /** @var array<int, string> */
    private const BOOLEAN_DISPLAY = ['نعم', 'لا', 'Yes', 'No'];

    /**
     * Boolean columns of this module, as declared by its Export class.
     *
     * @var array<int, string>
     */
    private const BOOLEAN_FIELDS = ['is_pwd', 'is_displaced', 'has_oedema', 'is_family_pwd'];

    /**
     * Whether this column has a synonym map at all. Free-text columns (names,
     * phone numbers, measurements, dates) have none and are left alone.
     */
    public static function handles(string $field): bool
    {
        return isset(self::FIELDS[$field]) || in_array($field, self::BOOLEAN_FIELDS, true);
    }

    /**
     * The spellings a file may use for this column, in both languages.
     *
     * @return array<int, string>
     */
    public static function accepted(string $field): array
    {
        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            return self::BOOLEAN_DISPLAY;
        }

        return self::FIELDS[$field]['display'] ?? [];
    }

    /**
     * Translate one uploaded cell into the value this column stores.
     *
     * Returns ['ok' => true, 'value' => mixed] with the canonical value, or
     * ['ok' => false, 'message' => string] naming the cell and the spellings
     * that would have been accepted.
     *
     * Anything that is not a filled string — a blank cell, a date, a number, a
     * boolean cell type — is handed back untouched, so the existing required
     * and type checks keep seeing exactly what they saw before.
     */
    public static function normalise(string $field, mixed $value): array
    {
        if (! self::handles($field) || ! is_string($value)) {
            return ['ok' => true, 'value' => $value];
        }

        $needle = self::key($value);

        if ($needle === '') {
            return ['ok' => true, 'value' => $value];
        }

        $lookup = self::lookup($field);

        if (array_key_exists($needle, $lookup)) {
            return ['ok' => true, 'value' => $lookup[$needle]];
        }

        return [
            'ok' => false,
            'message' => __('fields.import_invalid_value', [
                'value' => trim($value),
                'field' => __('fields.' . $field),
                'allowed' => implode('، ', self::accepted($field)),
            ]),
        ];
    }

    /**
     * The field's map, keyed by comparison key rather than by the readable
     * spelling it is written as above.
     *
     * @return array<string, string>
     */
    private static function lookup(string $field): array
    {
        static $cache = [];

        if (isset($cache[$field])) {
            return $cache[$field];
        }

        $values = in_array($field, self::BOOLEAN_FIELDS, true)
            ? self::BOOLEAN_VALUES
            : self::FIELDS[$field]['values'];

        $lookup = [];

        foreach ($values as $spelling => $stored) {
            $lookup[self::key((string) $spelling)] = $stored;
        }

        return $cache[$field] = $lookup;
    }

    /**
     * The comparison key: trimmed, inner runs of whitespace squeezed to one
     * space, lowercased. Invisible spacing an exported sheet carries (no-break
     * space, zero-width marks) counts as whitespace here. Nothing else is
     * touched — no diacritics stripped, no letters unified, no distance
     * measured — so a misspelling stays a misspelling.
     */
    private static function key(string $value): string
    {
        $value = preg_replace('/[\p{Z}\s\x{200B}-\x{200F}\x{FEFF}]+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }
}
