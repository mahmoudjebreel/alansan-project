<?php

namespace App\Exports;

use App\Filament\Resources\FollowUpChildResource;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FollowUpChildrenExport extends AbstractTableExport
{
    /**
     * Rows read per round trip when the export is streamed as CSV. Each
     * chunk is written and released before the next is read, so this is
     * the most the export ever holds in memory at once.
     */
    public const CSV_CHUNK = 500;

    /**
     * Columns the export derives and the import never reads back. Listed in
     * the import registry as computed so a value in an uploaded file can
     * never become a stored field.
     *
     * @see \App\Imports\ImportDefinition
     *
     * @var array<string>
     */
    public const COMPUTED_FIELDS = [
        'readmission_classification',
        'previous_episode_admission_date',
        'previous_episode_discharge_date',
        'previous_episode_outcome',
        'age_at_last_visit',
    ];

    protected ?int $maxVisits = null;

    public function fields(): array
    {
        return [
            'id_number', 'child_name', 'sex', 'dob', 'age_at_admission', 'age',
            'mobile_number', 'shelter_name', 'governorate', 'causes_of_admission',
            'admitted_with', 'admission_type', 'admission_date', 'discharge_date',
            'discharge_outcome', 'notes',
            // Export-only: how a readmission is classified and the closed
            // episode it follows, so the history can be read off the sheet.
            'readmission_classification',
            'previous_episode_admission_date',
            'previous_episode_discharge_date',
            'previous_episode_outcome',
            // Export-only: the age on the latest recorded visit, next to the
            // stored age at admission it is calculated the same way as.
            'age_at_last_visit',
        ];
    }

    public function booleanFields(): array
    {
        return [];
    }

    public function enumFields(): array
    {
        return ['sex', 'admitted_with', 'admission_type', 'discharge_outcome'];
    }

    public function query(): Builder
    {
        // The linked episode is read even from the trash: the classification
        // was settled when this episode was opened, exactly as the model's
        // own readmissionClassification() reads it.
        // @see \App\Models\FollowUpChild::readmissionClassification()
        return $this->query->with([
            'visits',
            'previousEpisode' => fn ($query) => $query->withTrashed(),
        ]);
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
            // Attended or missed, so a re-upload of this file keeps the
            // sequence of absences the record carries.
            $headings[] = __('fields.visit_status_n', ['n' => $i]);
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
            $row[] = $visit === null
                ? null
                : ($visit->isMissed() ? __('fields.visit_missed') : __('fields.visit_attended'));
        }

        return $row;
    }

    /**
     * The derived columns are resolved here; everything else is the stored
     * value, formatted exactly as before.
     */
    protected function formatValue(Model $record, string $field): mixed
    {
        /** @var FollowUpChild $record */
        return match ($field) {
            'readmission_classification' => FollowUpChildResource::readmissionClassificationLabel(
                $this->previousEpisodeOf($record)?->classifiesReturnAs(),
            ),
            'previous_episode_admission_date' => $this->previousEpisodeOf($record)?->admission_date?->format('Y-m-d'),
            'previous_episode_discharge_date' => $this->previousEpisodeOf($record)?->discharge_date?->format('Y-m-d'),
            'previous_episode_outcome' => $this->previousEpisodeOutcome($record),
            'age_at_last_visit' => FollowUpChild::formatAgeAtAdmission($record->dob, $this->latestVisitDate($record)),
            default => parent::formatValue($record, $field),
        };
    }

    /**
     * The closed episode this row is linked to, or null for a first admission
     * and for every row written before the link existed. Read through the
     * model's own relation, from the trash as well, and never inferred from
     * the child ID alone.
     */
    private function previousEpisodeOf(FollowUpChild $record): ?FollowUpChild
    {
        if (blank($record->previous_follow_up_child_id)) {
            return null;
        }

        if (! $record->relationLoaded('previousEpisode')) {
            $record->load(['previousEpisode' => fn ($query) => $query->withTrashed()]);
        }

        return $record->previousEpisode;
    }

    private function previousEpisodeOutcome(FollowUpChild $record): ?string
    {
        $outcome = $this->previousEpisodeOf($record)?->discharge_outcome;

        return filled($outcome) ? __('fields.' . $outcome) : null;
    }

    /**
     * The date of the latest recorded visit, or null while none carries one.
     * The visit's own date, never today's: the age is as of the visit.
     */
    private function latestVisitDate(FollowUpChild $record): mixed
    {
        return $record->visits
            ->pluck('visit_date')
            ->filter()
            ->max();
    }

    /**
     * The whole export as a CSV download, written chunk by chunk so a
     * history of any size streams through a bounded amount of memory.
     *
     * The BOM is what makes Excel read the file as UTF-8 and show the Arabic
     * as typed; without it the columns open as mojibake.
     */
    public function toCsvResponse(string $filename): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');

            $this->writeCsv($handle);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Write the headings and every row to an open stream.
     *
     * Rows are read in primary-key order by chunkById(), so a page boundary
     * can never repeat or skip a row however many episodes share one
     * admission date; the table's own sort is dropped for the same reason.
     *
     * @param  resource  $handle
     */
    public function writeCsv($handle): void
    {
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, $this->headings(), ',', '"', '\\', "\r\n");

        $this->query()
            ->reorder()
            ->chunkById(self::CSV_CHUNK, function (Collection $records) use ($handle): void {
                foreach ($records as $record) {
                    fputcsv($handle, $this->map($record), ',', '"', '\\', "\r\n");
                }

                // Push what is written so far to the client before the next
                // chunk is read. PHP's own output buffer, when one is on,
                // empties itself as it fills, so nothing accumulates here.
                flush();
            }, (new FollowUpChild)->qualifyColumn('id'), 'id');
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
