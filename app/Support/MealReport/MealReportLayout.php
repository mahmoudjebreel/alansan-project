<?php

namespace App\Support\MealReport;

/**
 * The exact sheet layout of the official MEAL monthly monitoring report.
 *
 * The merge maps and header captions below were extracted from the official
 * template workbook, so the generated file reproduces its multi-level headers
 * cell for cell. Each sheet also declares an ordered list of column keys: the
 * aggregation service returns one associative row per day keyed by those, and
 * the exporter writes them out in this order.
 *
 * The template's "KIT distribution" sheet is deliberately not represented -
 * it is filled in by hand and has no data source in this system.
 */
final class MealReportLayout
{
    public const SHEET_SCREENING = 'Screening Children and PBW';

    public const SHEET_IYCF = 'IYCF Groupe & individual';

    public const SHEET_CMAM = 'CMAM';

    /** Row the day-by-day data starts on, per sheet. */
    public const FIRST_DATA_ROW = [
        self::SHEET_SCREENING => 6,
        self::SHEET_IYCF => 7,
        self::SHEET_CMAM => 7,
    ];

    /** Row holding the innermost header captions, per sheet. */
    public const LEAF_ROW = [
        self::SHEET_SCREENING => 5,
        self::SHEET_IYCF => 6,
        self::SHEET_CMAM => 6,
    ];

    // ---- SCREENING ----  leaf row 5, 67 columns
    public const SCREENING_MERGES = [
        [2, 1, 5, 1, 'MBA '],
        [2, 2, 5, 2, 'MONTH'],
        [2, 3, 5, 3, 'DAY'],
        [2, 4, 2, 59, 'SCREENING (CEWs)'],
        [2, 60, 2, 61, ''],
        [2, 62, 2, 63, ''],
        [2, 64, 2, 65, ''],
        [3, 4, 3, 11, '6-23 months ' . "\n" . '(NEW)'],
        [3, 12, 3, 19, '6-23 months ' . "\n" . '(Follow-up)'],
        [3, 20, 3, 27, '24-59 months ' . "\n" . '(NEW)'],
        [3, 28, 3, 35, '24-59 months ' . "\n" . '(Follow-up)'],
        [3, 36, 3, 41, 'Pregnant women (NEW)'],
        [3, 42, 3, 47, 'Breastfeeding women (NEW)'],
        [3, 48, 3, 53, 'Pregnant women (Follow up)'],
        [3, 54, 3, 59, 'Breastfeeding women (Follow up)'],
        [3, 60, 3, 65, '6-59 months - PWD'],
        [3, 66, 3, 67, 'PBW-PWD'],
        [4, 4, 4, 5, 'Normal'],
        [4, 6, 4, 7, 'MAM'],
        [4, 8, 4, 9, 'SAM'],
        [4, 10, 4, 11, 'Oedema'],
        [4, 12, 4, 13, 'Normal'],
        [4, 14, 4, 15, 'MAM'],
        [4, 16, 4, 17, 'SAM'],
        [4, 18, 4, 19, 'Oedema'],
        [4, 20, 4, 21, 'Normal'],
        [4, 22, 4, 23, 'MAM'],
        [4, 24, 4, 25, 'SAM'],
        [4, 26, 4, 27, 'Oedema'],
        [4, 28, 4, 29, 'Normal'],
        [4, 30, 4, 31, 'MAM'],
        [4, 32, 4, 33, 'SAM'],
        [4, 34, 4, 35, 'Oedema'],
        [4, 36, 4, 38, '≥23cm' . "\n" . 'Not wasted'],
        [4, 39, 4, 41, '<23cm' . "\n" . 'Wasted'],
        [4, 42, 4, 44, '≥23cm' . "\n" . 'Not wasted'],
        [4, 45, 4, 47, '<23cm' . "\n" . 'Wasted'],
        [4, 48, 4, 50, '≥23cm' . "\n" . 'Not wasted'],
        [4, 51, 4, 53, '<23cm' . "\n" . 'Wasted'],
        [4, 54, 4, 56, '≥23cm' . "\n" . 'Not wasted'],
        [4, 57, 4, 59, '<23cm' . "\n" . 'Wasted'],
        [4, 60, 4, 61, 'Normal'],
        [4, 62, 4, 63, 'MAM'],
        [4, 64, 4, 65, 'SAM'],
        [4, 66, 4, 66, 'Normal'],
        [4, 67, 4, 67, 'MAM'],
    ];

    public const SCREENING_LEAF_LABELS = [
        '', '', '', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', '<18 years', '18-19 years', '20+ years', '<18 years', '18-19 years', '20+ years', '<18 years', '18-19 years', '20+ years', '<18 years', '18-19 years', '20+ years', '<18 years', '18-19 years', '20+ years', '<18 years', '18-19 years', '20+ years', '<18 years', '18-19 years', '20+ years', '<18 years', '18-19 years', '20+ years', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', '', '',
    ];

    // ---- IYCF ----  leaf row 6, 62 columns
    public const IYCF_MERGES = [
        [2, 1, 6, 1, 'MBA '],
        [2, 2, 6, 2, 'MONTH'],
        [2, 3, 6, 3, 'DAY'],
        [2, 4, 2, 49, 'INDIVIDUAL IYCF (Nutrition Counsellor)'],
        [2, 50, 2, 60, 'IYCF GROUP SESSIONS (Nutrition Counsellor)'],
        [3, 4, 3, 15, 'Individual Counselling Session: Caregivers'],
        [3, 16, 3, 23, 'Individual Counselling Session: Type of Help Provided'],
        [3, 24, 3, 35, 'Individual Counselling Session: Discharges' . "\n" . '' . "\n" . ''],
        [3, 36, 3, 36, 'Discharged from Programme'],
        [3, 37, 3, 44, 'Individual Counselling Session: Infants and Children'],
        [3, 45, 3, 48, 'Individual Counselling Session: Supported with BMS'],
        [3, 49, 6, 49, '# of BMS Violations reports'],
        [3, 50, 3, 50, 'Group Counselling Sessions'],
        [3, 51, 3, 60, 'Group Counselling Participants'],
        [4, 4, 4, 9, 'Total No. caregivers (0-23 months) receiving IYCF counselling '],
        [4, 10, 4, 15, 'Total No. of pregnant women (only) counselled on IYCF'],
        [4, 16, 4, 23, 'Total No. of caregivers (with children 0-23 months that) that received the following support '],
        [4, 24, 5, 26, 'Discharged after breastfeeding support'],
        [4, 27, 5, 29, 'Discharged after relactation support'],
        [4, 30, 5, 32, 'Discharged after wet nursing support'],
        [4, 33, 5, 35, 'Discharged after complementary feeding support'],
        [4, 36, 6, 36, 'PLW discharged from IYCF programme'],
        [4, 37, 4, 40, 'Total No. of children 0-5 months supported '],
        [4, 41, 4, 44, 'Total No. of children 6-23 months supported '],
        [4, 45, 4, 48, 'Total No. of children 0-5 months supported with BMS '],
        [4, 50, 6, 50, 'Support group session conducted'],
        [4, 51, 4, 60, 'Number of participants attending IYCFE group sessions'],
        [4, 61, 6, 61, 'Disababeled '],
        [5, 4, 5, 6, 'New'],
        [5, 7, 5, 9, 'Follow-up'],
        [5, 10, 5, 12, 'New'],
        [5, 13, 5, 15, 'Follow-up'],
        [5, 16, 5, 17, 'BF Support'],
        [5, 18, 5, 19, 'Relactation'],
        [5, 20, 5, 21, 'Complimentary Feeding'],
        [5, 22, 5, 23, 'Other'],
        [5, 37, 5, 38, 'New'],
        [5, 39, 5, 40, 'Follow-up'],
        [5, 41, 5, 42, 'New'],
        [5, 43, 5, 44, 'Follow-up'],
        [5, 45, 5, 46, 'New'],
        [5, 47, 5, 48, 'Follow-up'],
        [5, 51, 5, 52, 'Total Pregnant women'],
        [5, 53, 5, 54, 'Total Primary Caregiver with infant <6 months '],
        [5, 55, 5, 56, 'Total Primary Caregiver with child 6-23 months '],
        [5, 57, 5, 58, 'Total Grandmothers'],
        [5, 59, 5, 60, 'Total Woman of reproductive age'],
    ];

    public const IYCF_LEAF_LABELS = [
        '', '', '', '<18  years', '18-19 years', '20+ years', '<18  years', '18-19 years', '20+ years', '<18  years', '18-19 years', '20+ years', '<18  years', '18-19 years', '20+ years', 'New', 'Follow-up', 'New', 'Follow-up', 'New', 'Follow-up', 'New', 'Follow-up', 'Improved', 'Did Not Improve', 'Worsened', 'Improved', 'Did Not Improve', 'Worsened', 'Improved', 'Did Not Improve', 'Worsened', 'Improved', 'Did Not Improve', 'Worsened', '', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', '', '', 'New', 'Follow up', 'New', 'Follow up', 'New', 'Follow up', 'New', 'Follow up', 'New', 'Follow up', '', 'Total',
    ];

    // ---- CMAM ----  leaf row 6, 111 columns
    public const CMAM_MERGES = [
        [2, 1, 6, 1, 'MBA '],
        [2, 2, 6, 2, 'MONTH'],
        [2, 3, 6, 3, 'DAY'],
        [2, 4, 2, 111, 'CMAM (CMAM Nurse) '],
        [3, 4, 3, 15, 'MAM admissions'],
        [3, 16, 3, 41, 'MAM discharges'],
        [3, 44, 3, 47, 'Length of Stay'],
        [3, 48, 3, 59, 'SAM admissions'],
        [3, 60, 3, 71, 'SAM with oedema admissions'],
        [3, 72, 3, 99, 'SAM discharges (including oedema)'],
        [3, 100, 3, 103, 'Length of Stay'],
        [3, 104, 3, 107, 'SAM cases referred'],
        [3, 108, 3, 111, 'Caregivers counselled'],
        [4, 4, 4, 9, '6-23 months MAM Admitted'],
        [4, 10, 4, 15, '24-59 months MAM Admitted'],
        [4, 16, 4, 19, 'Case recovered'],
        [4, 20, 4, 23, 'Case defaulted (all)'],
        [4, 24, 4, 27, 'Case died'],
        [4, 28, 4, 31, 'Case did not respond to treatment'],
        [4, 32, 4, 35, 'Case referred for medical reasons'],
        [4, 36, 4, 39, 'Case discharged other'],
        [4, 40, 4, 43, 'Case discharged unknown'],
        [4, 44, 4, 47, 'Average Length of Stay of MAM cases'],
        [4, 48, 4, 53, '6-23 months SAM Admitted'],
        [4, 54, 4, 59, '24-59 months SAM Admitted'],
        [4, 60, 4, 65, '6-23 months SAM Admitted'],
        [4, 66, 4, 71, '24-59 months SAM Admitted'],
        [4, 72, 4, 75, 'Case recovered'],
        [4, 76, 4, 79, 'Case defaulted (all)'],
        [4, 80, 4, 83, 'Case died'],
        [4, 84, 4, 87, 'Case did not respond to treatment'],
        [4, 88, 4, 91, 'Case referred for medical reasons'],
        [4, 92, 4, 95, 'Case discharged other'],
        [4, 96, 4, 99, 'Case discharged unknown'],
        [4, 100, 4, 103, 'Average Length of Stay of SAM cases'],
        [4, 104, 4, 107, 'SAM case with complications referred to SC	'],
        [4, 108, 4, 111, 'Caregiver (child <5y) counselled on responsive feeding, caregiving and early stimulation'],
        [5, 4, 5, 5, 'New'],
        [5, 6, 5, 7, 'Relapse admission'],
        [5, 8, 5, 9, 'Readmission'],
        [5, 10, 5, 11, 'New'],
        [5, 12, 5, 13, 'Relapse admission'],
        [5, 14, 5, 15, 'Readmission'],
        [5, 16, 5, 17, '6-23 months '],
        [5, 18, 5, 19, '24- 59 months'],
        [5, 20, 5, 21, '6-23 months '],
        [5, 22, 5, 23, '24- 59 months'],
        [5, 24, 5, 25, '6-23 months '],
        [5, 26, 5, 27, '24- 59 months'],
        [5, 28, 5, 29, '6-23 months '],
        [5, 30, 5, 31, '24- 59 months'],
        [5, 32, 5, 33, '6-23 months '],
        [5, 34, 5, 35, '24- 59 months'],
        [5, 36, 5, 37, '6-23 months '],
        [5, 38, 5, 39, '24- 59 months'],
        [5, 40, 5, 41, '6-23 months '],
        [5, 42, 5, 43, '24- 59 months'],
        [5, 44, 5, 45, '6-23 months '],
        [5, 46, 5, 47, '24-59 months '],
        [5, 48, 5, 49, 'New'],
        [5, 50, 5, 51, 'Relapse admisison'],
        [5, 52, 5, 53, 'Readmission'],
        [5, 54, 5, 55, 'New'],
        [5, 56, 5, 57, 'Relapse admisison'],
        [5, 58, 5, 59, 'Readmission'],
        [5, 60, 5, 61, 'New'],
        [5, 62, 5, 63, 'Relapse admisison'],
        [5, 64, 5, 65, 'Readmission'],
        [5, 66, 5, 67, 'New'],
        [5, 68, 5, 69, 'Relapse admisison'],
        [5, 70, 5, 71, 'Readmission'],
        [5, 72, 5, 73, '6-23 months '],
        [5, 74, 5, 75, '24- 59 months'],
        [5, 76, 5, 77, '6-23 months '],
        [5, 78, 5, 79, '24- 59 months'],
        [5, 80, 5, 81, '6-23 months '],
        [5, 82, 5, 83, '24- 59 months'],
        [5, 84, 5, 85, '6-23 months '],
        [5, 86, 5, 87, '24- 59 months'],
        [5, 88, 5, 89, '6-23 months '],
        [5, 90, 5, 91, '24- 59 months'],
        [5, 92, 5, 93, '6-23 months '],
        [5, 94, 5, 95, '24- 59 months'],
        [5, 96, 5, 97, '6-23 months '],
        [5, 98, 5, 99, '24- 59 months'],
        [5, 100, 5, 101, '6-23 months '],
        [5, 102, 5, 103, '24-59 months '],
        [5, 104, 5, 105, '6-23 months '],
        [5, 106, 5, 107, '24-59 months '],
        [5, 108, 5, 109, 'New'],
        [5, 110, 5, 111, 'Returning'],
    ];

    public const CMAM_LEAF_LABELS = [
        '', '', '', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female', 'Male', 'Female',
    ];

    /**
     * Every sheet, in the order the workbook presents them.
     *
     * @return array<string>
     */
    public static function sheets(): array
    {
        return [self::SHEET_SCREENING, self::SHEET_IYCF, self::SHEET_CMAM];
    }

    /**
     * Header cells above the leaf row: [row, col, rowEnd, colEnd, caption].
     *
     * @return array<array{0:int,1:int,2:int,3:int,4:string}>
     */
    public static function merges(string $sheet): array
    {
        return match ($sheet) {
            self::SHEET_SCREENING => self::SCREENING_MERGES,
            self::SHEET_IYCF => self::IYCF_MERGES,
            self::SHEET_CMAM => self::CMAM_MERGES,
        };
    }

    /**
     * Captions for the leaf header row, one per column (blank where a merge
     * from an upper row already covers the column).
     *
     * @return array<string>
     */
    public static function leafLabels(string $sheet): array
    {
        return match ($sheet) {
            self::SHEET_SCREENING => self::SCREENING_LEAF_LABELS,
            self::SHEET_IYCF => self::IYCF_LEAF_LABELS,
            self::SHEET_CMAM => self::CMAM_LEAF_LABELS,
        };
    }

    /**
     * Ordered data keys, one per column. The first three are always the
     * MBA / MONTH / DAY stub columns.
     *
     * @return array<string>
     */
    public static function columns(string $sheet): array
    {
        return match ($sheet) {
            self::SHEET_SCREENING => self::screeningColumns(),
            self::SHEET_IYCF => self::iycfColumns(),
            self::SHEET_CMAM => self::cmamColumns(),
        };
    }

    /** Keys that hold an average rather than a count, so totals must not sum them. */
    public static function averageColumns(string $sheet): array
    {
        if ($sheet !== self::SHEET_CMAM) {
            return [];
        }

        return array_values(array_filter(
            self::cmamColumns(),
            fn (string $key): bool => str_contains($key, '_los_'),
        ));
    }

    /** @return array<string> */
    private static function stub(): array
    {
        return ['mba', 'month', 'day'];
    }

    /**
     * 3 stub + 32 child + 24 PBW + 6 child-PWD + 2 PBW-PWD = 67 columns.
     *
     * @return array<string>
     */
    private static function screeningColumns(): array
    {
        $columns = self::stub();

        foreach (['c6_23_new', 'c6_23_fu', 'c24_59_new', 'c24_59_fu'] as $group) {
            foreach (['normal', 'mam', 'sam', 'oedema'] as $status) {
                foreach (['male', 'female'] as $sex) {
                    $columns[] = "{$group}_{$status}_{$sex}";
                }
            }
        }

        foreach (['pw_new', 'bf_new', 'pw_fu', 'bf_fu'] as $group) {
            foreach (['not_wasted', 'wasted'] as $wasting) {
                foreach (['u18', 'a18_19', 'a20p'] as $age) {
                    $columns[] = "{$group}_{$wasting}_{$age}";
                }
            }
        }

        foreach (['normal', 'mam', 'sam'] as $status) {
            foreach (['male', 'female'] as $sex) {
                $columns[] = "pwd_{$status}_{$sex}";
            }
        }

        $columns[] = 'pbw_pwd_normal';
        $columns[] = 'pbw_pwd_mam';

        return $columns;
    }

    /**
     * 3 stub + 59 individual/group counselling columns = 62 columns.
     *
     * @return array<string>
     */
    private static function iycfColumns(): array
    {
        $columns = self::stub();

        foreach (['cg', 'pw'] as $who) {
            foreach (['new', 'fu'] as $visit) {
                foreach (['u18', 'a18_19', 'a20p'] as $age) {
                    $columns[] = "{$who}_{$visit}_{$age}";
                }
            }
        }

        foreach (['bf', 'relactation', 'cf', 'other'] as $help) {
            foreach (['new', 'fu'] as $visit) {
                $columns[] = "help_{$help}_{$visit}";
            }
        }

        foreach (['bf', 'relactation', 'wet_nursing', 'cf'] as $help) {
            foreach (['improved', 'not_improved', 'worsened'] as $outcome) {
                $columns[] = "disch_{$help}_{$outcome}";
            }
        }

        $columns[] = 'plw_discharged';

        foreach (['ch0_5', 'ch6_23'] as $band) {
            foreach (['new', 'fu'] as $visit) {
                foreach (['male', 'female'] as $sex) {
                    $columns[] = "{$band}_{$visit}_{$sex}";
                }
            }
        }

        foreach (['new', 'fu'] as $visit) {
            foreach (['male', 'female'] as $sex) {
                $columns[] = "bms0_5_{$visit}_{$sex}";
            }
        }

        $columns[] = 'bms_violations';
        $columns[] = 'group_sessions';

        foreach (['pregnant', 'cg_infant', 'cg_child', 'grandmother', 'wra'] as $category) {
            foreach (['new', 'fu'] as $visit) {
                $columns[] = "part_{$category}_{$visit}";
            }
        }

        $columns[] = 'participants_disabled';
        $columns[] = 'participants_total';

        return $columns;
    }

    /**
     * 3 stub + 108 CMAM columns = 111 columns.
     *
     * @return array<string>
     */
    private static function cmamColumns(): array
    {
        $ages = ['6_23', '24_59'];
        $sexes = ['male', 'female'];
        $admissionTypes = ['new', 'relapse', 'readmission'];
        $outcomes = ['recovered', 'defaulted', 'died', 'no_response', 'referred_medical', 'other', 'unknown'];

        $columns = self::stub();

        $admissions = function (string $prefix) use ($ages, $admissionTypes, $sexes): array {
            $keys = [];
            foreach ($ages as $age) {
                foreach ($admissionTypes as $type) {
                    foreach ($sexes as $sex) {
                        $keys[] = "{$prefix}_{$age}_{$type}_{$sex}";
                    }
                }
            }

            return $keys;
        };

        $discharges = function (string $prefix) use ($outcomes, $ages, $sexes): array {
            $keys = [];
            foreach ($outcomes as $outcome) {
                foreach ($ages as $age) {
                    foreach ($sexes as $sex) {
                        $keys[] = "{$prefix}_{$outcome}_{$age}_{$sex}";
                    }
                }
            }

            return $keys;
        };

        $byAgeAndSex = function (string $prefix) use ($ages, $sexes): array {
            $keys = [];
            foreach ($ages as $age) {
                foreach ($sexes as $sex) {
                    $keys[] = "{$prefix}_{$age}_{$sex}";
                }
            }

            return $keys;
        };

        $columns = array_merge(
            $columns,
            $admissions('mam_adm'),
            $discharges('mam_dis'),
            $byAgeAndSex('mam_los'),
            $admissions('sam_adm'),
            $admissions('sam_oedema_adm'),
            $discharges('sam_dis'),
            $byAgeAndSex('sam_los'),
            $byAgeAndSex('sam_referred'),
        );

        foreach (['new', 'returning'] as $type) {
            foreach ($sexes as $sex) {
                $columns[] = "cg_counselled_{$type}_{$sex}";
            }
        }

        return $columns;
    }
}
