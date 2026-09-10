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
 * Admission (Children -> Follow Up Child)
 *   Every screening is recorded in Children, whatever the reading says. A
 *   reading of MAM or SAM additionally opens a follow-up episode for the same
 *   child; a Normal reading is simply the Children visit and nothing else.
 *   The Children row is the screening record and the follow-up record is the
 *   treatment episode - they answer different questions, so the screening is
 *   never withheld from Children just because it also started an episode.
 *
 * Discharge (Follow Up Child -> Children)
 *   A followed-up child whose latest visit comes back Normal is cured, and
 *   returns to Children as a new visit carrying that final measurement.
 *
 * Neither direction is wired into the bulk Excel import, which has no way to
 * ask a person anything and keeps writing rows exactly as it always did.
 */
final class ChildFollowUpTransfer
{
    /**
     * Open a follow-up episode for a child who has just been screened at MAM
     * or SAM, unless one is already open for them.
     *
     * The screening that triggered the referral becomes visit 1 of the episode,
     * and the Children row it came from is linked so the two can be read
     * together afterwards.
     *
     * Returns null when nothing was opened: a Normal reading, a blank
     * measurement, or a child who is already being followed up.
     */
    public static function refer(Child $child): ?FollowUpChild
    {
        $fi = MuacClassifier::classify($child->muac_mm);

        if (! MuacClassifier::isMalnourished($fi)) {
            return null;
        }

        if (static::hasOpenEpisode($child->child_id)) {
            return null;
        }

        // A child whose previous episode closed with an outcome that allows
        // it is the same child coming back: the new episode is a readmission
        // and says so. After any other closed outcome the episode opens as a
        // first admission, exactly as it always did. The closed episode is
        // only read here, never written.
        $previous = FollowUpChild::readmittableEpisodeFor($child->child_id);

        return static::open($child, $fi, $previous);
    }

    /**
     * Readmission from the Referral Centre: a new episode for a screened
     * child whose every episode on file is closed.
     *
     * The same transfer as refer(), reached only by a person choosing
     * "Readmission" for one child. It refuses - returns null - when there is
     * no closed episode to follow on from, when one is still open, or when
     * the reading is not one the programme admits on; nothing is written in
     * any of those cases and the closed history is not touched in any case.
     */
    public static function readmit(Child $child): ?FollowUpChild
    {
        $fi = MuacClassifier::classify($child->muac_mm);

        if (! MuacClassifier::isMalnourished($fi)) {
            return null;
        }

        if (static::hasOpenEpisode($child->child_id)) {
            return null;
        }

        // Only a closed episode whose outcome allows a readmission qualifies.
        $previous = FollowUpChild::readmittableEpisodeFor($child->child_id);

        if ($previous === null) {
            return null;
        }

        return static::open($child, $fi, $previous);
    }

    /**
     * Readmission from the Follow Up Child module itself: a new episode
     * opened from a closed one, for a child who came back without passing
     * through a Children screening first.
     *
     * The identity fields are copied from the closed record - it is the same
     * child - and the reading entered becomes visit 1 of the new episode,
     * exactly as a screening does. The closed record is read and never
     * written: it keeps its outcome, its discharge date and every visit.
     *
     * The reading entered classifies the readmission, by the one shared
     * classifier: SAM or MAM is stored as the admission classification, and
     * a Normal reading opens the episode with none - the child is back under
     * monitoring, not admitted with malnutrition, and visit 1 carries the
     * Normal FI itself. The previous episode's classification is never
     * copied. Only a reading that classifies as nothing at all is refused.
     *
     * @param  array{admission_date?: mixed, visit_date?: mixed, muac: mixed}  $data
     */
    public static function readmitFromEpisode(FollowUpChild $previous, array $data): ?FollowUpChild
    {
        if (! $previous->canBeReadmitted()) {
            return null;
        }

        $fi = MuacClassifier::classify($data['muac'] ?? null);

        if ($fi === null) {
            return null;
        }

        $admissionDate = static::date($data['admission_date'] ?? null) ?? Carbon::today();
        $visitDate = static::date($data['visit_date'] ?? null) ?? $admissionDate;

        return DB::transaction(function () use ($previous, $fi, $admissionDate, $visitDate, $data): FollowUpChild {
            $followUpChild = FollowUpChild::create([
                'id_number' => $previous->id_number,
                'child_name' => $previous->child_name,
                'sex' => $previous->sex,
                'dob' => $previous->dob,
                'age' => FollowUpChild::formatCurrentAge($previous->dob),
                'mobile_number' => $previous->mobile_number,
                'shelter_name' => $previous->shelter_name,
                'governorate' => $previous->governorate ?: 'gaza',
                'causes_of_admission' => 'malnutrition',
                // The column holds SAM or MAM only; a Normal readmission is
                // admitted with neither.
                'admitted_with' => MuacClassifier::isMalnourished($fi) ? $fi : null,
                'admission_type' => FollowUpChild::ADMISSION_READMISSION,
                'admission_date' => $admissionDate,
                'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
                'discharge_date' => null,
                'source_child_visit_id' => null,
                'previous_follow_up_child_id' => $previous->getKey(),
            ]);

            $followUpChild->visits()->create([
                'visit_number' => 1,
                'visit_date' => $visitDate,
                'muac' => $data['muac'],
            ]);

            return $followUpChild;
        });
    }

    /**
     * Write the episode and its first visit, inside one transaction.
     *
     * With a closed episode to follow on from, the row is a readmission and
     * carries the link; otherwise it is a first admission. Either way the
     * previous record, if any, is never written.
     */
    private static function open(Child $child, string $fi, ?FollowUpChild $previous): FollowUpChild
    {
        $readingDate = static::date($child->date_of_reporting) ?? Carbon::today();
        $dob = static::date($child->date_of_birth);

        return DB::transaction(function () use ($child, $fi, $readingDate, $dob, $previous): FollowUpChild {
            $followUpChild = FollowUpChild::create([
                'id_number' => $child->child_id,
                'child_name' => $child->name,
                'sex' => static::toFollowUpSex($child->sex),
                'dob' => $dob,
                'age' => FollowUpChild::formatCurrentAge($dob),
                'mobile_number' => $child->phone_number,
                'shelter_name' => static::shelterNameFrom($child),
                'governorate' => $child->governorate ?: 'gaza',
                // Fixed by the rule that produced this admission.
                'causes_of_admission' => 'malnutrition',
                'admitted_with' => $fi,
                'admission_type' => $previous
                    ? FollowUpChild::ADMISSION_READMISSION
                    : FollowUpChild::ADMISSION_NEW,
                'admission_date' => Carbon::today(),
                'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
                'discharge_date' => null,
                'source_child_visit_id' => $child->getKey(),
                'previous_follow_up_child_id' => $previous?->getKey(),
            ]);

            $followUpChild->visits()->create([
                'visit_number' => 1,
                'visit_date' => $readingDate,
                // FI is derived from this MUAC by the visit model itself.
                'muac' => $child->muac_mm,
            ]);

            return $followUpChild;
        });
    }

    /**
     * Whether the child already has a follow-up episode that has not been
     * closed. Referring the same child twice would count one episode as two in
     * every report the module feeds.
     */
    public static function hasOpenEpisode(mixed $childId): bool
    {
        if (blank($childId)) {
            return false;
        }

        return FollowUpChild::query()
            ->where('id_number', $childId)
            ->where(function ($query): void {
                $query->whereNull('discharge_outcome')
                    ->orWhereNotIn('discharge_outcome', FollowUpChild::CLOSING_OUTCOMES);
            })
            ->exists();
    }

    /**
     * Discharge: a followed-up child whose latest visit came back Normal is
     * cured, and returns to the Children module as a new visit.
     *
     * Only ever called for the "Cured" outcome; the other four outcomes are
     * human decisions that close the record without producing anything here.
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
     */
    private static function shelterNameFrom(Child $child): ?string
    {
        foreach (['location', 'neighbourhood', 'type_of_site', 'municipality'] as $field) {
            if (filled($child->{$field})) {
                return $child->{$field};
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
