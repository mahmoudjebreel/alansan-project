<?php

namespace App\Support;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The two automatic transfers between the Children module and the Follow Up
 * Child module.
 *
 * Both directions are only ever reached from a manual Create/Edit form, after
 * the user has confirmed the move. Nothing here is wired into the bulk Excel
 * import, which has no way to ask a person anything and therefore keeps
 * writing rows exactly as it always did.
 */
final class ChildFollowUpTransfer
{
    /**
     * Admission: a child screened at MAM or SAM is enrolled into follow-up
     * instead of being recorded as an ordinary Children visit.
     *
     * The reading that triggered the referral becomes visit 1 of the new
     * record, and no row is written to the Children table at all - counting
     * the same screening in both modules would double it in every report.
     *
     * @param  array<string, mixed>  $childData  Validated Children form data.
     */
    public static function admit(array $childData, string $fi): FollowUpChild
    {
        $childId = $childData['child_id'] ?? null;
        $readingDate = static::date($childData['date_of_reporting'] ?? null) ?? Carbon::today();
        $dob = static::date($childData['date_of_birth'] ?? null);

        return DB::transaction(function () use ($childData, $childId, $fi, $readingDate, $dob): FollowUpChild {
            $followUpChild = FollowUpChild::create([
                'id_number' => $childId,
                'child_name' => $childData['name'] ?? null,
                'sex' => static::toFollowUpSex($childData['sex'] ?? null),
                'dob' => $dob,
                'age' => FollowUpChild::formatCurrentAge($dob),
                'mobile_number' => $childData['phone_number'] ?? null,
                'shelter_name' => static::shelterNameFrom($childData),
                'governorate' => $childData['governorate'] ?? 'gaza',
                // Fixed by the rule that produced this admission.
                'causes_of_admission' => 'malnutrition',
                'admitted_with' => $fi,
                'admission_date' => Carbon::today(),
                'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
                'discharge_date' => null,
                // No Children row is written for this reading, so the link
                // points at the child's previous visit when there is one.
                'source_child_visit_id' => ChildDuplicateChecker::latestActiveVisit($childId)?->getKey(),
            ]);

            $followUpChild->visits()->create([
                'visit_number' => 1,
                'visit_date' => $readingDate,
                // FI is derived from this MUAC by the visit model itself.
                'muac' => $childData['muac_mm'] ?? null,
            ]);

            return $followUpChild;
        });
    }

    /**
     * Discharge: a followed-up child whose latest visit came back Normal is
     * cured, and returns to the Children module as a new visit.
     *
     * Only ever called for the "Cured" outcome; the other four outcomes are
     * human decisions that lock the record without producing anything here.
     */
    public static function discharge(FollowUpChild $followUpChild, FollowUpChildVisit $latestVisit): Child
    {
        $visitDate = $latestVisit->visit_date ? Carbon::parse($latestVisit->visit_date) : Carbon::today();

        return Child::create([
            // Stated explicitly by the rule, not derived from the relapse check.
            'visit_type' => 'new',
            'name' => $followUpChild->child_name,
            'child_id' => $followUpChild->id_number,
            'phone_number' => $followUpChild->mobile_number,
            'sex' => static::toChildSex($followUpChild->sex),
            'date_of_birth' => $followUpChild->dob,
            'date_of_reporting' => $visitDate,
            // FI follows from this MUAC through the Child model's own mutator.
            'muac_mm' => $latestVisit->muac,
            'governorate' => $followUpChild->governorate ?: 'gaza',
            'location' => $followUpChild->shelter_name,
            // The Children form's own defaults for the two columns the table
            // will not store as NULL and the follow-up record does not carry.
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'source_follow_up_child_id' => $followUpChild->getKey(),
        ]);
    }

    /**
     * Whether a discharged follow-up record carries everything the Children
     * table refuses to store as NULL. Sex is the only one that cannot be
     * defaulted without inventing data about a real child.
     */
    public static function canDischargeToChildren(FollowUpChild $followUpChild): bool
    {
        return filled(static::toChildSex($followUpChild->sex))
            && filled($followUpChild->child_name)
            && filled($followUpChild->id_number);
    }

    /**
     * Children stores the words, Follow Up Child stores the initials.
     */
    public static function toFollowUpSex(?string $sex): ?string
    {
        return match ($sex) {
            'male' => 'M',
            'female' => 'F',
            'M', 'F' => $sex,
            default => null,
        };
    }

    public static function toChildSex(?string $sex): ?string
    {
        return match ($sex) {
            'M' => 'male',
            'F' => 'female',
            'male', 'female' => $sex,
            default => null,
        };
    }

    /**
     * The best shelter/site name the Children form actually collects. The
     * follow-up module asks for one field; Children spreads the same idea over
     * several optional ones, so the most specific filled value wins.
     *
     * @param  array<string, mixed>  $childData
     */
    private static function shelterNameFrom(array $childData): ?string
    {
        foreach (['location', 'neighbourhood', 'type_of_site', 'municipality'] as $field) {
            if (filled($childData[$field] ?? null)) {
                return $childData[$field];
            }
        }

        return null;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
