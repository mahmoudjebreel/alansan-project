<?php

namespace App\Exports;

use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FollowUpChildrenExport extends AbstractTableExport
{
    protected ?int $maxVisits = null;

    public function fields(): array
    {
        return [
            'id_number', 'child_name', 'sex', 'dob', 'age_at_admission', 'age',
            'mobile_number', 'shelter_name', 'governorate', 'causes_of_admission',
            'admitted_with', 'admission_date', 'discharge_date', 'discharge_outcome',
            'notes',
        ];
    }

    public function booleanFields(): array
    {
        return [];
    }

    public function enumFields(): array
    {
        return ['sex', 'admitted_with', 'discharge_outcome'];
    }

    public function query(): Builder
    {
        return $this->query->with('visits');
    }

    public function headings(): array
    {
        $headings = parent::headings();

        foreach (range(1, $this->maxVisits()) as $i) {
            $headings[] = __('fields.visit_date_n', ['n' => $i]);
            $headings[] = __('fields.visit_muac_n', ['n' => $i]);
            // FI is the reading the programme actually reports on, so it is
            // exported next to the measurement rather than left to the reader
            // to classify by hand.
            $headings[] = __('fields.visit_fi_n', ['n' => $i]);
        }

        return $headings;
    }

    public function map($record): array
    {
        $row = parent::map($record);

        $visits = $record->visits->keyBy('visit_number');

        foreach (range(1, $this->maxVisits()) as $i) {
            $visit = $visits->get($i);
            $row[] = $visit?->visit_date?->format('Y-m-d');
            $row[] = $visit?->muac;
            $row[] = $visit?->fi;
        }

        return $row;
    }

    /**
     * Highest visit number present in the exported data set (at least 1).
     */
    protected function maxVisits(): int
    {
        if ($this->maxVisits !== null) {
            return $this->maxVisits;
        }

        $ids = (clone $this->query)
            ->reorder()
            ->select((new FollowUpChild)->qualifyColumn('id'));

        return $this->maxVisits = max(1, min(
            FollowUpChild::MAX_VISITS,
            (int) FollowUpChildVisit::query()
                ->whereIn('follow_up_child_id', $ids)
                ->max('visit_number'),
        ));
    }
}
