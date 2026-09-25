<?php

namespace Tests\Feature;

use App\Filament\Pages\ReferralCenter;
use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\FollowUpChildResource\Pages\ViewFollowUpChild;
use App\Exports\FollowUpChildrenExport;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\ChildFollowUpTransfer;
use App\Support\ImportSchema;
use App\Support\Referral\ReferralProcessor;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Died is final. A child whose latest closed follow-up episode ended as died
 * is never entered again after the death - not as a new admission, not as a
 * follow-up, not as a readmission or a relapse, not by referral and not by an
 * upload - while the death itself and everything before it stay on file,
 * readable and exportable.
 */
class DiedTerminalTest extends TestCase
{
    use RefreshDatabase;

    private const CHILD_ID = '470900001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Carbon::setTestNow('2026-09-20');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =================================================================
    // I. A return after a death is refused
    // =================================================================

    public function test_i_a_return_after_died_is_refused_and_the_death_is_untouched(): void
    {
        $died = $this->diedEpisode();
        $before = $died->getAttributes();

        $this->assertTrue(FollowUpChild::isTerminal(self::CHILD_ID));
        $this->assertNull(ChildFollowUpTransfer::refer($this->screening('2026-09-10', 110)));
        $this->assertNull(FollowUpChild::readmissionClassificationFor(self::CHILD_ID));

        $this->assertSame(1, FollowUpChild::where('id_number', self::CHILD_ID)->count());
        $this->assertSame($before, $died->fresh()->getAttributes());
    }

    public function test_the_children_form_refuses_a_new_screening_after_the_death(): void
    {
        $this->diedEpisode();

        Livewire::test(CreateChild::class)
            ->fillForm($this->formData(110))
            ->call('create')
            ->assertHasFormErrors(['child_id']);

        $this->assertSame(0, Child::where('child_id', self::CHILD_ID)->count());
        $this->assertSame(1, FollowUpChild::where('id_number', self::CHILD_ID)->count());
    }

    public function test_the_children_form_still_accepts_other_children(): void
    {
        $this->diedEpisode();

        Livewire::test(CreateChild::class)
            ->fillForm($this->formData(130, '470900099'))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Child::where('child_id', '470900099')->count());
    }

    // =================================================================
    // J. Readmission after a death is refused
    // =================================================================

    public function test_j_readmission_after_died_is_refused_everywhere(): void
    {
        $died = $this->diedEpisode();

        $this->assertFalse($died->canBeReadmitted());
        $this->assertNull(FollowUpChild::readmittableEpisodeFor(self::CHILD_ID));
        $this->assertNull(ChildFollowUpTransfer::readmitFromEpisode($died, [
            'admission_date' => '2026-09-10', 'visit_date' => '2026-09-10', 'muac' => 110,
        ]));
        $this->assertNull(ChildFollowUpTransfer::readmit($this->screening('2026-09-10', 110)));

        Livewire::test(ViewFollowUpChild::class, ['record' => $died->getKey()])
            ->assertActionHidden('readmission');

        $this->assertSame(1, FollowUpChild::where('id_number', self::CHILD_ID)->count());
    }

    public function test_j_a_death_after_a_default_blocks_the_readmission_the_default_would_allow(): void
    {
        // Defaulted first, then (a later episode) died: the death is latest.
        $this->episode(['admission_date' => '2026-05-01', 'discharge_date' => '2026-05-20', 'discharge_outcome' => 'defaulted']);
        $this->diedEpisode();

        $this->assertNull(FollowUpChild::readmittableEpisodeFor(self::CHILD_ID));
        $this->assertNull(ChildFollowUpTransfer::readmit($this->screening('2026-09-10', 110)));
    }

    // =================================================================
    // K. A relapse after a death is refused
    // =================================================================

    public function test_k_a_relapse_after_died_is_refused(): void
    {
        // Cured SAM, then a relapse episode that ended as died.
        $cured = $this->episode(['admission_date' => '2026-05-01', 'discharge_date' => '2026-06-01', 'discharge_outcome' => 'cured']);
        $this->diedEpisode(['previous_follow_up_child_id' => $cured->getKey()]);

        $this->assertNull(ChildFollowUpTransfer::refer($this->screening('2026-09-10', 110)));
        $this->assertNull(FollowUpChild::readmissionClassificationFor(self::CHILD_ID));
        $this->assertSame(2, FollowUpChild::where('id_number', self::CHILD_ID)->count());
    }

    // =================================================================
    // Referral Centre
    // =================================================================

    public function test_the_referral_centre_refuses_a_child_who_died(): void
    {
        $this->diedEpisode();
        $child = $this->screening('2026-09-10', 110);

        $result = ReferralProcessor::refer([$child->getKey()]);

        $this->assertSame(0, $result['referred']);
        $this->assertSame(1, $result['skipped_died']);
        $this->assertSame(1, FollowUpChild::where('id_number', self::CHILD_ID)->count());

        // And the one-child Readmission action is not offered either.
        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', null)
            ->assertTableActionHidden('readmit', $child);
    }

    // =================================================================
    // Which episode decides
    // =================================================================

    public function test_a_death_moved_to_the_trash_still_ends_the_history(): void
    {
        $this->diedEpisode()->delete();

        $this->assertTrue(FollowUpChild::isTerminal(self::CHILD_ID));
        $this->assertNull(ChildFollowUpTransfer::refer($this->screening('2026-09-10', 110)));
    }

    public function test_the_latest_closed_episode_decides(): void
    {
        // A death that is not the latest closed episode (a later episode was
        // recorded and closed after it) does not decide.
        $this->diedEpisode(['admission_date' => '2026-05-01', 'discharge_date' => '2026-05-20']);
        $this->episode(['admission_date' => '2026-06-01', 'discharge_date' => '2026-07-01', 'discharge_outcome' => 'non_responded']);

        $this->assertFalse(FollowUpChild::isTerminal(self::CHILD_ID));
    }

    // =================================================================
    // Uploads
    // =================================================================

    public function test_a_follow_up_upload_refuses_an_episode_admitted_after_the_death(): void
    {
        $this->diedEpisode();

        $result = $this->importFollowUp('2026-09-05');

        $this->assertSame(0, $result['imported']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString(__('ui.died_terminal.message'), implode(' ', $result['errors']));
        $this->assertSame(1, FollowUpChild::where('id_number', self::CHILD_ID)->count());
    }

    public function test_a_follow_up_upload_of_the_history_itself_is_not_refused(): void
    {
        // Re-uploading the died episode (or anything before it) is history,
        // not a re-entry: it is not refused by the death rule.
        $this->diedEpisode();

        $result = $this->importFollowUp('2026-08-01', 'died', '2026-08-26');

        $this->assertSame([], $result['errors']);
    }

    public function test_a_children_upload_refuses_a_screening_dated_after_the_death(): void
    {
        $this->diedEpisode();

        $result = $this->importChildren('2026-09-05');

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(__('ui.died_terminal.message'), implode(' ', $result['errors']));
        $this->assertSame(0, Child::where('child_id', self::CHILD_ID)->count());
    }

    public function test_a_children_upload_keeps_screenings_from_before_the_death(): void
    {
        $this->diedEpisode();

        $result = $this->importChildren('2026-08-10');

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
    }

    // =================================================================
    // The history stays readable
    // =================================================================

    public function test_the_died_episode_stays_visible_and_exportable(): void
    {
        $died = $this->diedEpisode();

        Livewire::test(ViewFollowUpChild::class, ['record' => $died->getKey()])
            ->assertOk();

        $handle = fopen('php://memory', 'w+');
        (new FollowUpChildrenExport(FollowUpChild::query()))->writeCsv($handle);
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $this->assertStringContainsString(self::CHILD_ID, $content);
        $this->assertStringContainsString(__('fields.died'), $content);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function diedEpisode(array $attributes = []): FollowUpChild
    {
        return $this->episode(array_merge([
            'admission_date' => '2026-08-01',
            'discharge_date' => '2026-08-26',
            'discharge_outcome' => 'died',
        ], $attributes));
    }

    private function episode(array $attributes): FollowUpChild
    {
        return FollowUpChild::create(array_merge([
            'id_number' => self::CHILD_ID,
            'child_name' => 'Test child',
            'sex' => 'M',
            'dob' => '2025-01-01',
            'mobile_number' => '0599123456',
            'shelter_name' => 'Mosaab camp',
            'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
        ], $attributes))->fresh();
    }

    private function screening(string $date, int $muac): Child
    {
        return Child::create([
            'visit_type' => 'new',
            'name' => 'Test child',
            'child_id' => self::CHILD_ID,
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => $date,
            'sex' => 'male',
            'date_of_birth' => '2025-01-01',
            'muac_mm' => $muac,
            'has_oedema' => false,
            'is_pwd' => false,
            'governorate' => 'gaza',
            'location' => 'Mosaab camp',
            'type_of_site' => 'Mossab Camp',
        ]);
    }

    private function formData(int $muac, string $childId = self::CHILD_ID): array
    {
        return [
            'child_id' => $childId,
            'name' => 'طفل الاختبار',
            'phone_number' => '0591234567',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => now()->format('Y-m-d'),
            'screener_profession' => 'CHW',
            'sex' => 'male',
            'date_of_birth' => now()->subMonths(18)->format('Y-m-d'),
            'muac_mm' => $muac,
            'governorate' => 'gaza',
            'municipality' => 'gaza',
            'location' => 'مركز الإيواء أ',
            'type_of_site' => 'El Salam Camp',
            'mother_marital_status' => 'متزوجة',
        ];
    }

    private function importFollowUp(string $admissionDate, ?string $outcome = null, ?string $dischargeDate = null): array
    {
        $headings = (new ImportSchema(ImportDefinition::get('follow_up_children')))->headings();
        $row = array_fill(0, count($headings), null);

        $values = [
            __('fields.id_number') => self::CHILD_ID,
            __('fields.child_name') => 'Test child',
            __('fields.governorate') => 'Gaza',
            __('fields.admission_date') => $admissionDate,
            __('fields.visit_date_n', ['n' => 1]) => $admissionDate,
            __('fields.visit_muac_n', ['n' => 1]) => 110,
        ];

        if ($outcome !== null) {
            $values[__('fields.discharge_outcome')] = __('fields.' . $outcome);
            $values[__('fields.discharge_date')] = $dischargeDate;
        }

        foreach ($values as $heading => $value) {
            $index = array_search($heading, $headings, true);
            $this->assertNotFalse($index, "No [{$heading}] column.");
            $row[$index] = $value;
        }

        return $this->import('follow_up_children', [$headings, $row]);
    }

    private function importChildren(string $date): array
    {
        $headings = (new ImportSchema(ImportDefinition::get('children')))->headings();
        $row = array_fill(0, count($headings), null);

        $values = [
            __('fields.visit_type') => __('fields.new'),
            __('fields.child_id') => self::CHILD_ID,
            __('fields.name') => 'Test child',
            __('fields.sex') => __('fields.male'),
            __('fields.governorate') => 'Gaza',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => $date,
            __('fields.muac_mm') => 130,
        ];

        foreach ($values as $heading => $value) {
            $index = array_search($heading, $headings, true);
            $this->assertNotFalse($index, "No [{$heading}] column.");
            $row[$index] = $value;
        }

        return $this->import('children', [$headings, $row]);
    }

    private function import(string $key, array $rows): array
    {
        $export = new class($rows) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'died-test-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            ImportDefinition::get($key),
            Storage::disk('local')->path($name),
        );
    }
}
