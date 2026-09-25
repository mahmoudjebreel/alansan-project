<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\ChildResource\Pages\EditChild;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Services\MealReportService;
use App\Support\ChildFollowUpTransfer;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\ReportPeriod;
use App\Support\Referral\ReferralProcessor;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H5: a follow-up episode opened from a Children screening is admitted on the
 * screening's own date (date_of_reporting), never on the day somebody acted
 * on it. Visit 1 keeps the screening date as before.
 */
class FollowUpAdmissionDateTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_children_form_admits_on_the_screening_date(): void
    {
        Carbon::setTestNow('2026-08-19 10:00:00');

        // The form accepts today or later; a later reporting date shows the
        // admission follows the screening, not the day of the click.
        Livewire::test(CreateChild::class)
            ->fillForm($this->formData('2026-08-22', 110))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertAdmittedOn('470800001', '2026-08-22');
    }

    public function test_an_edit_that_opens_follow_up_later_admits_on_the_screening_date(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');

        $child = $this->screening('470800002', '2026-08-19', 130);

        Livewire::test(EditChild::class, ['record' => $child->getKey()])
            ->set('referFollowUpOnSave', true)
            ->fillForm(['muac_mm' => 110])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertAdmittedOn('470800002', '2026-08-19');
    }

    public function test_the_referral_centre_admits_on_the_screening_date(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');

        $child = $this->screening('470800003', '2026-08-19', 110);

        $this->assertSame(1, ReferralProcessor::refer([$child->getKey()])['referred']);

        $this->assertAdmittedOn('470800003', '2026-08-19');
    }

    public function test_a_referral_centre_readmission_admits_on_the_screening_date(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');

        FollowUpChild::factory()->create([
            'id_number' => '470800004', 'admitted_with' => 'SAM',
            'admission_date' => '2026-06-01', 'discharge_date' => '2026-06-20', 'discharge_outcome' => 'defaulted',
        ]);

        $episode = ChildFollowUpTransfer::readmit($this->screening('470800004', '2026-08-19', 110));

        $this->assertNotNull($episode);
        $this->assertSame('2026-08-19', $episode->admission_date->format('Y-m-d'));
        $this->assertSame('2026-08-19', $episode->visits()->first()->visit_date->format('Y-m-d'));
    }

    public function test_meal_counts_the_admission_in_the_month_of_the_screening(): void
    {
        // Screened on 28 August, referred on 3 September: an August admission.
        Carbon::setTestNow('2026-09-03 10:00:00');

        $child = $this->screening('470800005', '2026-08-28', 110);
        ReferralProcessor::refer([$child->getKey()]);

        $sheet = app(MealReportService::class)
            ->buildPeriod(ReportPeriod::make(2026, 8, 9), null)[MealReportLayout::SHEET_CMAM];

        $this->assertSame(1, $this->admissions($sheet['monthTotals'][8]));
        $this->assertSame(0, $this->admissions($sheet['monthTotals'][9]));
    }

    private function assertAdmittedOn(string $idNumber, string $date): void
    {
        $episode = FollowUpChild::where('id_number', $idNumber)->sole();

        $this->assertSame($date, $episode->admission_date->format('Y-m-d'), 'Admission date is the screening date.');
        $this->assertSame($date, $episode->visits()->first()->visit_date->format('Y-m-d'), 'Visit 1 keeps the screening date.');
    }

    private function admissions(array $row): int
    {
        $sum = 0;

        foreach ($row as $key => $value) {
            if (str_contains($key, '_adm_') && is_numeric($value)) {
                $sum += (int) $value;
            }
        }

        return $sum;
    }

    private function screening(string $idNumber, string $date, int $muac): Child
    {
        return Child::create([
            'visit_type' => 'new',
            'name' => 'Test child',
            'child_id' => $idNumber,
            'phone_number' => '0591234567',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'screener_profession' => 'CHW',
            'date_of_reporting' => $date,
            'sex' => 'male',
            'date_of_birth' => '2025-01-01',
            'muac_mm' => $muac,
            'has_oedema' => false,
            'is_pwd' => false,
            'governorate' => 'gaza',
            'municipality' => 'gaza',
            'location' => 'Mosaab camp',
            'type_of_site' => 'Mossab Camp',
            'mother_marital_status' => 'متزوجة',
        ]);
    }

    private function formData(string $date, int $muac): array
    {
        return [
            'child_id' => '470800001',
            'name' => 'طفل الاختبار',
            'phone_number' => '0591234567',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => $date,
            'screener_profession' => 'CHW',
            'sex' => 'male',
            'date_of_birth' => '2025-01-01',
            'muac_mm' => $muac,
            'governorate' => 'gaza',
            'municipality' => 'gaza',
            'location' => 'مركز الإيواء أ',
            'type_of_site' => 'El Salam Camp',
            'mother_marital_status' => 'متزوجة',
        ];
    }
}
