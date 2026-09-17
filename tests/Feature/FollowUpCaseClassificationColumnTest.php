<?php

namespace Tests\Feature;

use App\Exports\FollowUpChildrenExport;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Models\FollowUpChild;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The case classification column on the Follow Up Children listing.
 *
 * Five cases, and not one of them decided here: the three readmission
 * classifications come straight from the model method the spreadsheet's own
 * readmission_classification column is written from, and the other two are
 * what is left - an episode that follows on from nothing, named by its own
 * outcome when that outcome is a default, and a plain new admission
 * otherwise.
 *
 * @see \App\Filament\Resources\FollowUpChildResource::caseClassification()
 * @see \App\Models\FollowUpChild::readmissionClassification()
 */
class FollowUpCaseClassificationColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function closedEpisode(string $idNumber, string $outcome, string $admittedWith = 'SAM'): FollowUpChild
    {
        return FollowUpChild::factory()->create([
            'id_number' => $idNumber,
            'admitted_with' => $admittedWith,
            'admission_type' => FollowUpChild::ADMISSION_NEW,
            'admission_date' => '2026-01-10',
            'discharge_date' => '2026-02-10',
            'discharge_outcome' => $outcome,
        ]);
    }

    /**
     * The episode opened after a closed one, linked the way the readmission
     * workflow and the transfer link it.
     */
    private function episodeAfter(FollowUpChild $previous, array $attributes = []): FollowUpChild
    {
        return FollowUpChild::factory()->create(array_merge([
            'id_number' => $previous->id_number,
            'admitted_with' => 'SAM',
            'admission_type' => FollowUpChild::ADMISSION_READMISSION,
            'admission_date' => '2026-03-01',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
            'previous_follow_up_child_id' => $previous->getKey(),
        ], $attributes));
    }

    // -----------------------------------------------------------------
    // The five cases
    // -----------------------------------------------------------------

    public function test_a_first_admission_is_a_new_case(): void
    {
        $record = FollowUpChild::factory()->create([
            'id_number' => '100000001',
            'admission_type' => FollowUpChild::ADMISSION_NEW,
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
            'previous_follow_up_child_id' => null,
        ]);

        $this->assertSame(FollowUpChildResource::CASE_NEW, FollowUpChildResource::caseClassification($record));
        $this->assertSame(__('fields.admission_new'), FollowUpChildResource::caseClassificationLabel($record));
    }

    public function test_an_episode_that_ended_as_defaulted_is_a_defaulted_case(): void
    {
        $record = FollowUpChild::factory()->create([
            'id_number' => '100000002',
            'admission_type' => FollowUpChild::ADMISSION_NEW,
            'discharge_date' => '2026-02-10',
            'discharge_outcome' => FollowUpChild::DEFAULTED_OUTCOME,
            'previous_follow_up_child_id' => null,
        ]);

        $this->assertSame(FollowUpChildResource::CASE_DEFAULTED, FollowUpChildResource::caseClassification($record));
        $this->assertSame(__('fields.defaulted'), FollowUpChildResource::caseClassificationLabel($record));
    }

    public function test_an_episode_after_a_defaulted_one_is_a_readmission_after_defaulted(): void
    {
        $record = $this->episodeAfter(
            $this->closedEpisode('100000003', FollowUpChild::DEFAULTED_OUTCOME),
        );

        $this->assertSame(
            FollowUpChild::READMISSION_AFTER_DEFAULTED,
            FollowUpChildResource::caseClassification($record),
        );
        $this->assertSame(
            __('fields.readmission_after_defaulted'),
            FollowUpChildResource::caseClassificationLabel($record),
        );
    }

    /**
     * The three approved other-pathway exits, each of them a readmission
     * after other and none of them anything else.
     */
    public function test_each_other_pathway_exit_is_a_readmission_after_other(): void
    {
        foreach (['discharge_to_opt', 'discharge_to_other', 'referred_medical_inpt'] as $i => $outcome) {
            $record = $this->episodeAfter(
                $this->closedEpisode('20000000' . $i, $outcome),
            );

            $this->assertSame(
                FollowUpChild::READMISSION_AFTER_OTHER,
                FollowUpChildResource::caseClassification($record),
                $outcome,
            );
            $this->assertSame(
                __('fields.readmission_after_other'),
                FollowUpChildResource::caseClassificationLabel($record),
                $outcome,
            );
        }
    }

    public function test_an_episode_after_a_cured_sam_or_mam_one_is_a_readmission_after_relapse(): void
    {
        foreach (['SAM', 'MAM'] as $i => $admittedWith) {
            $record = $this->episodeAfter(
                $this->closedEpisode('30000000' . $i, FollowUpChild::CURED_OUTCOME, $admittedWith),
            );

            $this->assertSame(
                FollowUpChild::READMISSION_AFTER_RELAPSE,
                FollowUpChildResource::caseClassification($record),
                $admittedWith,
            );
            $this->assertSame(
                __('fields.readmission_after_relapse'),
                FollowUpChildResource::caseClassificationLabel($record),
                $admittedWith,
            );
        }
    }

    /**
     * What an episode follows on from is what the episode is: its own
     * ending is the discharge outcome column, not this one.
     */
    public function test_a_readmission_that_itself_defaulted_keeps_its_readmission_case(): void
    {
        $record = $this->episodeAfter(
            $this->closedEpisode('100000004', FollowUpChild::DEFAULTED_OUTCOME),
            [
                'discharge_date' => '2026-04-01',
                'discharge_outcome' => FollowUpChild::DEFAULTED_OUTCOME,
            ],
        );

        $this->assertSame(
            FollowUpChild::READMISSION_AFTER_DEFAULTED,
            FollowUpChildResource::caseClassification($record),
        );
    }

    /**
     * An exit the module classifies as nothing - non-responded, died, a cure
     * with no SAM/MAM classification - leaves the next episode a new case,
     * exactly as the model decides it.
     */
    public function test_an_exit_that_classifies_as_nothing_leaves_a_new_case(): void
    {
        $unclassified = [
            ['non_responded', 'SAM'],
            ['died', 'SAM'],
            [FollowUpChild::CURED_OUTCOME, 'normal'],
        ];

        foreach ($unclassified as $i => [$outcome, $admittedWith]) {
            $record = $this->episodeAfter(
                $this->closedEpisode('40000000' . $i, $outcome, $admittedWith),
            );

            $this->assertSame(
                FollowUpChildResource::CASE_NEW,
                FollowUpChildResource::caseClassification($record),
                $outcome . '/' . $admittedWith,
            );
        }
    }

    // -----------------------------------------------------------------
    // The same answer as the spreadsheet
    // -----------------------------------------------------------------

    /**
     * The listing column and the export column are the same statement about
     * the same episode, whatever the history behind it.
     */
    public function test_the_column_agrees_with_the_spreadsheet_for_every_case(): void
    {
        $histories = [
            [FollowUpChild::DEFAULTED_OUTCOME, 'SAM'],
            ['discharge_to_opt', 'SAM'],
            ['discharge_to_other', 'SAM'],
            ['referred_medical_inpt', 'SAM'],
            [FollowUpChild::CURED_OUTCOME, 'SAM'],
            [FollowUpChild::CURED_OUTCOME, 'MAM'],
            [FollowUpChild::CURED_OUTCOME, 'normal'],
            ['non_responded', 'SAM'],
            ['died', 'SAM'],
        ];

        foreach ($histories as $i => [$outcome, $admittedWith]) {
            $this->episodeAfter($this->closedEpisode('50000000' . $i, $outcome, $admittedWith));
        }

        // A first admission and a defaulted one, neither following anything.
        FollowUpChild::factory()->create([
            'id_number' => '510000001',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
            'previous_follow_up_child_id' => null,
        ]);
        FollowUpChild::factory()->create([
            'id_number' => '510000002',
            'discharge_date' => '2026-02-10',
            'discharge_outcome' => FollowUpChild::DEFAULTED_OUTCOME,
            'previous_follow_up_child_id' => null,
        ]);

        $export = new FollowUpChildrenExport(FollowUpChild::query());
        $position = array_search('readmission_classification', $export->fields(), true);

        foreach (FollowUpChild::all() as $record) {
            $fromSheet = $export->map($record)[$position];

            $case = FollowUpChildResource::caseClassification($record);
            $fromList = array_key_exists($case, FollowUpChildResource::readmissionClassificationOptions())
                ? FollowUpChildResource::caseClassificationOptions()[$case]
                : null;

            $this->assertSame($fromSheet, $fromList, 'episode ' . $record->getKey());
        }
    }

    // -----------------------------------------------------------------
    // The filter
    // -----------------------------------------------------------------

    /**
     * Each of the five filter values selects exactly the episodes the column
     * names with that value - no more and no fewer.
     */
    public function test_the_filter_selects_exactly_what_the_column_names(): void
    {
        $histories = [
            [FollowUpChild::DEFAULTED_OUTCOME, 'SAM'],
            ['discharge_to_opt', 'SAM'],
            ['discharge_to_other', 'SAM'],
            ['referred_medical_inpt', 'SAM'],
            [FollowUpChild::CURED_OUTCOME, 'SAM'],
            [FollowUpChild::CURED_OUTCOME, 'MAM'],
            [FollowUpChild::CURED_OUTCOME, 'normal'],
            ['non_responded', 'SAM'],
            ['died', 'SAM'],
        ];

        foreach ($histories as $i => [$outcome, $admittedWith]) {
            $this->episodeAfter($this->closedEpisode('60000000' . $i, $outcome, $admittedWith));
        }

        FollowUpChild::factory()->create([
            'id_number' => '610000001',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
            'previous_follow_up_child_id' => null,
        ]);
        FollowUpChild::factory()->create([
            'id_number' => '610000002',
            'discharge_date' => '2026-02-10',
            'discharge_outcome' => FollowUpChild::DEFAULTED_OUTCOME,
            'previous_follow_up_child_id' => null,
        ]);

        $expected = FollowUpChild::all()
            ->groupBy(fn (FollowUpChild $record): string => FollowUpChildResource::caseClassification($record))
            ->map(fn ($group) => $group->pluck('id')->sort()->values()->all());

        foreach (array_keys(FollowUpChildResource::caseClassificationOptions()) as $case) {
            $selected = FollowUpChildResource::applyCaseClassificationFilter(FollowUpChild::query(), $case)
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $this->assertSame($expected->get($case, []), $selected, $case);
        }
    }

    /**
     * The classification was settled when the episode was opened, so
     * trashing the history it follows must not change what the filter
     * selects any more than it changes what the column says.
     */
    public function test_the_filter_reads_a_trashed_previous_episode_like_the_column_does(): void
    {
        $previous = $this->closedEpisode('700000001', FollowUpChild::DEFAULTED_OUTCOME);
        $record = $this->episodeAfter($previous);

        $previous->delete();

        $this->assertSame(
            FollowUpChild::READMISSION_AFTER_DEFAULTED,
            FollowUpChildResource::caseClassification($record->fresh()),
        );

        $selected = FollowUpChildResource::applyCaseClassificationFilter(
            FollowUpChild::query(),
            FollowUpChild::READMISSION_AFTER_DEFAULTED,
        )->pluck('id')->all();

        $this->assertSame([$record->getKey()], $selected);
    }

    public function test_no_filter_value_leaves_the_listing_untouched(): void
    {
        FollowUpChild::factory()->count(3)->create();

        $this->assertSame(
            3,
            FollowUpChildResource::applyCaseClassificationFilter(FollowUpChild::query(), null)->count(),
        );
    }

    // -----------------------------------------------------------------
    // The listing itself
    // -----------------------------------------------------------------

    public function test_the_listing_renders_the_column(): void
    {
        $record = $this->episodeAfter(
            $this->closedEpisode('800000001', FollowUpChild::DEFAULTED_OUTCOME),
        );

        Livewire::test(ListFollowUpChildren::class)
            ->assertCanSeeTableRecords([$record])
            ->assertTableColumnExists('case_classification')
            ->assertTableColumnStateSet(
                'case_classification',
                __('fields.readmission_after_defaulted'),
                $record,
            );
    }

    /**
     * The column is one the user can put away, and it is not the table's
     * only readmission signal being replaced: the columns that were there
     * before are all still there.
     */
    public function test_the_column_is_toggleable_and_leaves_the_existing_columns_alone(): void
    {
        $existing = [
            'id_number', 'child_name', 'sex', 'shelter_name', 'admission_date',
            'admitted_with', 'admission_type', 'latest_visit_number',
            'latest_visit_date', 'discharge_date', 'discharge_outcome',
            'visits_count', 'missed_visits', 'latest_muac', 'record_state',
        ];

        $component = Livewire::test(ListFollowUpChildren::class);

        foreach ($existing as $column) {
            $component->assertTableColumnExists($column);
        }

        $table = $component->instance()->getTable();

        $this->assertTrue($table->getColumn('case_classification')->isToggleable());
    }

    /**
     * The tabs decide which episodes the listing shows, and the column is
     * not allowed to have an opinion about that.
     */
    public function test_the_column_does_not_change_which_records_the_listing_shows(): void
    {
        $open = FollowUpChild::factory()->create([
            'id_number' => '900000001',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);
        $closed = $this->closedEpisode('900000002', FollowUpChild::DEFAULTED_OUTCOME);

        Livewire::test(ListFollowUpChildren::class)
            ->assertCanSeeTableRecords([$open, $closed]);
    }

    /**
     * Both languages name the column and all five of its values.
     */
    public function test_the_column_is_translated_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $this->assertNotSame('fields.case_classification', __('fields.case_classification'), $locale);

            foreach (FollowUpChildResource::caseClassificationOptions() as $case => $label) {
                $this->assertNotSame('', trim($label), $locale . '/' . $case);
            }
        }
    }

    /**
     * The filter is on the table and offers the five cases.
     */
    public function test_the_filter_is_on_the_listing(): void
    {
        $table = Livewire::test(ListFollowUpChildren::class)->instance()->getTable();

        $filter = $table->getFilter('case_classification');

        $this->assertNotNull($filter);
        $this->assertSame(
            array_keys(FollowUpChildResource::caseClassificationOptions()),
            array_keys($filter->getOptions()),
        );
    }

    /**
     * The filter narrows the listing itself, not only a query built by hand.
     */
    public function test_the_filter_narrows_the_listing(): void
    {
        $readmission = $this->episodeAfter(
            $this->closedEpisode('910000001', FollowUpChild::DEFAULTED_OUTCOME),
        );
        $plain = FollowUpChild::factory()->create([
            'id_number' => '910000002',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
            'previous_follow_up_child_id' => null,
        ]);

        Livewire::test(ListFollowUpChildren::class)
            ->filterTable('case_classification', FollowUpChild::READMISSION_AFTER_DEFAULTED)
            ->assertCanSeeTableRecords([$readmission])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    /**
     * The filter is a WHERE clause, so it must compose with the tab's own
     * restriction rather than replace it.
     */
    public function test_the_filter_composes_with_an_existing_restriction(): void
    {
        $previous = $this->closedEpisode('920000001', FollowUpChild::DEFAULTED_OUTCOME);
        $open = $this->episodeAfter($previous);
        $closedReadmission = $this->episodeAfter(
            $this->closedEpisode('920000002', FollowUpChild::DEFAULTED_OUTCOME),
            ['discharge_date' => '2026-04-01', 'discharge_outcome' => FollowUpChild::CURED_OUTCOME],
        );

        $query = FollowUpChild::query()->whereNotIn('discharge_outcome', FollowUpChild::CLOSING_OUTCOMES);

        $selected = FollowUpChildResource::applyCaseClassificationFilter(
            $query,
            FollowUpChild::READMISSION_AFTER_DEFAULTED,
        )->pluck('id')->all();

        $this->assertSame([$open->getKey()], $selected);
        $this->assertNotContains($closedReadmission->getKey(), $selected);
    }

    /**
     * A closure passed to the filter must not leak into unrelated queries.
     */
    public function test_the_filter_leaves_other_queries_alone(): void
    {
        FollowUpChild::factory()->count(2)->create();

        FollowUpChildResource::applyCaseClassificationFilter(
            FollowUpChild::query(),
            FollowUpChildResource::CASE_NEW,
        )->get();

        $this->assertSame(2, FollowUpChild::query()->count());
    }

    /**
     * The scope the listing exports is not touched by the new column.
     */
    public function test_the_export_query_is_unchanged(): void
    {
        $record = FollowUpChild::factory()->create(['id_number' => '930000001']);

        $page = Livewire::test(ListFollowUpChildren::class)->instance();

        /** @var Builder $query */
        $query = $page->allHistoryExportQuery();

        $this->assertSame([$record->getKey()], $query->pluck('id')->all());
    }
}
