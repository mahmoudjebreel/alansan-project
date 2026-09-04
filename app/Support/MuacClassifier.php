<?php

namespace App\Support;

/**
 * The single MUAC -> FI classifier shared by every module that records a
 * mid-upper arm circumference.
 *
 * The thresholds are exactly the ones the Children module has always applied
 * and are deliberately not restated anywhere else: SAM at 115 mm or below,
 * MAM between that and 125 mm, Normal from 125 mm up. Callers that used to
 * carry their own copy now delegate here so a reading can never be classified
 * two different ways in two different screens.
 */
final class MuacClassifier
{
    public const SAM = 'SAM';

    public const MAM = 'MAM';

    public const NORMAL = 'Normal';

    /** A reading at or below this many millimetres is SAM. */
    public const SAM_MAX_MM = 115;

    /** Below this many millimetres, and above SAM, is MAM. */
    public const MAM_MAX_MM = 125;

    /**
     * Classify a MUAC measurement in millimetres. A blank measurement has no
     * classification at all, which is not the same thing as "Normal".
     */
    public static function classify(mixed $muacMm): ?string
    {
        if ($muacMm === null || $muacMm === '') {
            return null;
        }

        $muacMm = (float) $muacMm;

        return match (true) {
            $muacMm <= self::SAM_MAX_MM => self::SAM,
            $muacMm < self::MAM_MAX_MM => self::MAM,
            default => self::NORMAL,
        };
    }

    /**
     * Whether a classification is one the malnutrition programme admits on.
     */
    public static function isMalnourished(?string $fi): bool
    {
        return in_array($fi, [self::SAM, self::MAM], true);
    }

    /**
     * The Filament badge colour every FI indicator in the project uses.
     */
    public static function color(?string $fi): string
    {
        return match ($fi) {
            self::SAM => 'danger',
            self::MAM => 'warning',
            self::NORMAL => 'success',
            default => 'gray',
        };
    }

    /**
     * The same three states as inline input classes, for the disabled text
     * inputs that show FI inside a form.
     *
     * @return array<string, string>
     */
    public static function inputAttributes(?string $fi): array
    {
        return match ($fi) {
            self::SAM => ['class' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400'],
            self::MAM => ['class' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400'],
            self::NORMAL => ['class' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400'],
            default => [],
        };
    }
}
