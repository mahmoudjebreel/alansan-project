<?php

namespace App\Exports;

use App\Models\IndividualCounseling;
use App\Models\IndividualCounselingFollowup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class IndividualCounselingExport extends AbstractTableExport
{
    protected ?int $maxFollowups = null;

    public function fields(): array
    {
        return [
            'date', 'health_educator', 'child_name', 'child_visit_type', 'child_dob',
            'age_months', 'gender', 'child_age_lactated', 'feeding_type', 'p_l',
            'muac', 'muac_degree', 'mother_id_number', 'mother_name',
            'mother_visit_type', 'mother_dob', 'mother_age_years', 'mobile_number',
            'shelter_name', 'consultation', 'iycf_form_filled', 'status', 'outcome',
            // Assess and Analyze are two separate base-visit columns here;
            // the merged "Assess and analyze" belongs to the session groups.
            'assess', 'analyze', 'act', 'pregnancy', 'lactating', 'delivery_date',
            'pregnancy_count',
        ];
    }

    public function booleanFields(): array
    {
        return ['iycf_form_filled'];
    }

    public function enumFields(): array
    {
        return ['child_visit_type', 'p_l', 'mother_visit_type', 'consultation', 'status', 'outcome'];
    }

    public function query(): Builder
    {
        return $this->query->with('followups');
    }

    /**
     * The own columns, then one numbered group of three columns per follow-up
     * session: date, merged assessment, action.
     */
    public function headings(): array
    {
        $headings = parent::headings();

        foreach (range(1, $this->maxFollowups()) as $i) {
            $headings[] = __('fields.followup_date_n', ['n' => $i]);
            $headings[] = __('fields.followup_assess_n', ['n' => $i]);
            $headings[] = __('fields.followup_act_n', ['n' => $i]);
        }

        return $headings;
    }

    public function map($record): array
    {
        $row = parent::map($record);

        // Position in the (sort_order) sequence, not sort_order itself: the
        // column a session lands in is its place in the list, and sort_order
        // is only guaranteed to order them, not to start at 1.
        $followups = $record->followups->values();

        foreach (range(1, $this->maxFollowups()) as $i) {
            $followup = $followups->get($i - 1);
            $row[] = $followup?->follow_up_visit_date?->format('Y-m-d');
            $row[] = $followup?->assess_and_analyze;
            $row[] = $followup?->act;
        }

        return $row;
    }

    /**
     * Most follow-up sessions held by any one record in the exported data set,
     * never more than the six a record may hold and never fewer than 1, so the
     * column group is always present.
     */
    protected function maxFollowups(): int
    {
        if ($this->maxFollowups !== null) {
            return $this->maxFollowups;
        }

        $ids = (clone $this->query)
            ->reorder()
            ->select((new IndividualCounseling)->qualifyColumn('id'));

        $perRecord = IndividualCounselingFollowup::query()
            ->whereIn('individual_counseling_id', $ids)
            ->groupBy('individual_counseling_id')
            ->selectRaw('COUNT(*) as aggregate');

        return $this->maxFollowups = max(1, min(
            IndividualCounseling::MAX_FOLLOWUP_SESSIONS,
            (int) DB::query()
                ->fromSub($perRecord, 'counts')
                ->max('aggregate'),
        ));
    }
}
