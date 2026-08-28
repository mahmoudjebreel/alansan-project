<?php

namespace App\Support\MealReport;

/**
 * One site, four spellings.
 *
 * The modules this report reads from never agreed on how to name a camp:
 *
 *   Child / PregnantLactatingWoman  `type_of_site`   'Mossab Camp', 'El Salam Camp', ...
 *   GroupSession / IndividualCounseling `shelter_name`   'mosaab_camp', 'el_salam', ...
 *   FollowUpChild                   `shelter_name`   free text, typed by hand
 *
 * The report filter speaks the Child/PLW vocabulary (the one the request named),
 * and this class translates a chosen site into whatever each source table
 * actually stores. FollowUpChild has no controlled vocabulary at all, so it is
 * matched with LIKE patterns - see matchesFollowUpChild().
 */
final class SiteVocabulary
{
    public const ALL = '__all__';

    /**
     * Canonical site => how each module spells it.
     *
     * @var array<string, array{label_ar: string, type_of_site: string, shelter_name: string, like: array<string>}>
     */
    private const SITES = [
        'Mossab Camp' => [
            'label_ar' => 'مخيم مصعب',
            'type_of_site' => 'Mossab Camp',
            'shelter_name' => 'mosaab_camp',
            'like' => ['%mosaab%', '%mossab%', '%مصعب%'],
        ],
        'El Salam Camp' => [
            'label_ar' => 'مخيم السلام',
            'type_of_site' => 'El Salam Camp',
            'shelter_name' => 'el_salam',
            'like' => ['%salam%', '%السلام%'],
        ],
        'Mahabba Camp' => [
            'label_ar' => 'مخيم المحبة',
            'type_of_site' => 'Mahabba Camp',
            'shelter_name' => 'mahabba',
            'like' => ['%mahabba%', '%المحبة%', '%محبة%'],
        ],
        'El Qoqa' => [
            'label_ar' => 'القوقا',
            'type_of_site' => 'El Qoqa',
            'shelter_name' => 'el_qoqa',
            'like' => ['%qoqa%', '%القوقا%', '%قوقا%'],
        ],
    ];

    /** @return array<string> */
    public static function keys(): array
    {
        return array_keys(self::SITES);
    }

    /**
     * Options for the report's site selector, localised.
     *
     * @return array<string, string>
     */
    public static function options(bool $includeAll = true): array
    {
        $options = [];

        foreach (self::SITES as $key => $site) {
            $options[$key] = app()->getLocale() === 'ar' ? $site['label_ar'] : $key;
        }

        if ($includeAll) {
            $options = [self::ALL => __('fields.meal_all_sites')] + $options;
        }

        return $options;
    }

    public static function exists(?string $site): bool
    {
        return $site !== null && array_key_exists($site, self::SITES);
    }

    /** The label printed in the report's "MBA" stub column. */
    public static function label(?string $site): string
    {
        if ($site === null || $site === self::ALL) {
            return 'All sites';
        }

        return $site;
    }

    /** Value stored in Child.type_of_site / PregnantLactatingWoman.type_of_site. */
    public static function typeOfSite(string $site): string
    {
        return self::SITES[$site]['type_of_site'];
    }

    /** Value stored in GroupSession.shelter_name / IndividualCounseling.shelter_name. */
    public static function shelterName(string $site): string
    {
        return self::SITES[$site]['shelter_name'];
    }

    /**
     * LIKE patterns for FollowUpChild.shelter_name, which is free text.
     *
     * @return array<string>
     */
    public static function freeTextPatterns(string $site): array
    {
        return self::SITES[$site]['like'];
    }
}
