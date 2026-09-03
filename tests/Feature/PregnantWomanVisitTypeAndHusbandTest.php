<?php

namespace Tests\Feature;

use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages\CreatePregnantLactatingWoman;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages\EditPregnantLactatingWoman;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use App\Support\PregnantWomanDuplicateChecker;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PregnantWomanVisitTypeAndHusbandTest extends TestCase
{
    use RefreshDatabase;

    // Rule 1 - trashed records do not exist as far as duplicate checking goes.

    public function test_soft_deleted_record_is_not_treated_as_duplicate(): void
    {
        PregnantLactatingWoman::factory()
            ->create(['mother_id' => '123456789', 'status_type' => 'pregnant'])
            ->delete();

        $this->assertFalse(PregnantWomanDuplicateChecker::hasActiveVisit('123456789'));
        $this->assertNull(PregnantWomanDuplicateChecker::latestActiveVisit('123456789'));
        $this->assertSame('new', PregnantWomanDuplicateChecker::resolveVisitType('123456789', 'pregnant'));
    }

    public function test_latest_active_visit_ignores_trashed_and_picks_most_recent(): void
    {
        PregnantLactatingWoman::factory()->create(['mother_id' => '123456789', 'date_of_reporting' => '2026-01-01']);
        $latest = PregnantLactatingWoman::factory()->create(['mother_id' => '123456789', 'date_of_reporting' => '2026-03-01']);
        PregnantLactatingWoman::factory()->create(['mother_id' => '123456789', 'date_of_reporting' => '2026-06-01'])->delete();

        $this->assertTrue($latest->is(PregnantWomanDuplicateChecker::latestActiveVisit('123456789')));
    }

    public function test_latest_active_visit_can_ignore_the_record_being_edited(): void
    {
        $woman = PregnantLactatingWoman::factory()->create(['mother_id' => '123456789']);

        $this->assertNull(PregnantWomanDuplicateChecker::latestActiveVisit('123456789', $woman));
    }

    // Rule 2 - a first visit is always "new".

    public function test_first_visit_is_always_new(): void
    {
        foreach (['pregnant', 'lactating', 'pregnant_lactating'] as $statusType) {
            $this->assertSame('new', PregnantWomanDuplicateChecker::resolveVisitType('987654321', $statusType));
        }
    }

    // Rule 3 - switching status is a new care cycle, with one exception:
    // pregnant + breastfeeding -> pregnant only is the same pregnancy carrying
    // on (only the breastfeeding stopped), so it stays a follow up.

    /**
     * Every combination of the three statuses, nine in all.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function statusSwitchMatrix(): array
    {
        return [
            'pregnant to pregnant stays a follow up' => ['pregnant', 'pregnant', 'follow_up'],
            'pregnant to lactating is a new cycle' => ['pregnant', 'lactating', 'new'],
            'pregnant to combined is a new cycle' => ['pregnant', 'pregnant_lactating', 'new'],

            'lactating to lactating stays a follow up' => ['lactating', 'lactating', 'follow_up'],
            'lactating to pregnant is a new cycle' => ['lactating', 'pregnant', 'new'],
            'lactating to combined is a new cycle' => ['lactating', 'pregnant_lactating', 'new'],

            'combined to combined stays a follow up' => ['pregnant_lactating', 'pregnant_lactating', 'follow_up'],
            // The exception: the pregnancy did not change, only the feeding.
            'combined to pregnant stays a follow up' => ['pregnant_lactating', 'pregnant', 'follow_up'],
            // Not covered by the exception: no pregnancy is being carried on.
            'combined to lactating is a new cycle' => ['pregnant_lactating', 'lactating', 'new'],
        ];
    }

    #[DataProvider('statusSwitchMatrix')]
    public function test_status_switch_decides_the_visit_type(string $previous, string $current, string $expected): void
    {
        PregnantLactatingWoman::factory()->create([
            'mother_id' => '123456789',
            'status_type' => $previous,
            'date_of_reporting' => '2026-01-01',
        ]);

        $this->assertSame($expected, PregnantWomanDuplicateChecker::resolveVisitType('123456789', $current));
    }

    public function test_visit_stays_a_follow_up_until_a_status_is_picked(): void
    {
        PregnantLactatingWoman::factory()->create(['mother_id' => '123456789', 'status_type' => 'pregnant']);

        $this->assertSame('follow_up', PregnantWomanDuplicateChecker::resolveVisitType('123456789', null));
    }

    public function test_switch_is_measured_against_the_latest_active_visit_only(): void
    {
        PregnantLactatingWoman::factory()->create([
            'mother_id' => '123456789',
            'status_type' => 'lactating',
            'date_of_reporting' => '2026-01-01',
        ]);
        PregnantLactatingWoman::factory()->create([
            'mother_id' => '123456789',
            'status_type' => 'pregnant',
            'date_of_reporting' => '2026-03-01',
        ]);

        // Against the pregnant baseline, another pregnant visit is a follow up...
        $this->assertSame('follow_up', PregnantWomanDuplicateChecker::resolveVisitType('123456789', 'pregnant'));
        // ...and a lactating one opens a new cycle.
        $this->assertSame('new', PregnantWomanDuplicateChecker::resolveVisitType('123456789', 'lactating'));
    }

    // Server-side enforcement: the locked form field is never trusted.

    public function test_create_page_forces_visit_type_server_side(): void
    {
        $page = new CreatePregnantLactatingWoman;
        $mutate = (new \ReflectionClass($page))->getMethod('mutateFormDataBeforeCreate');
        $mutate->setAccessible(true);

        // No record at all -> new, whatever the form claims.
        $this->assertSame('new', $mutate->invoke($page, [
            'mother_id' => '123456789',
            'visit_type' => 'follow_up',
            'status_type' => 'pregnant',
        ])['visit_type']);

        // Soft-deleted record -> still new.
        PregnantLactatingWoman::factory()
            ->create(['mother_id' => '123456789', 'status_type' => 'pregnant'])
            ->delete();
        $this->assertSame('new', $mutate->invoke($page, [
            'mother_id' => '123456789',
            'visit_type' => 'follow_up',
            'status_type' => 'lactating',
        ])['visit_type']);

        // Active pregnant record -> staying pregnant is a follow up...
        PregnantLactatingWoman::factory()->create([
            'mother_id' => '123456789',
            'status_type' => 'pregnant',
            'date_of_reporting' => '2026-03-01',
        ]);
        $this->assertSame('follow_up', $mutate->invoke($page, [
            'mother_id' => '123456789',
            'visit_type' => 'new',
            'status_type' => 'pregnant',
        ])['visit_type']);

        // ...and switching to lactating opens a new cycle.
        $this->assertSame('new', $mutate->invoke($page, [
            'mother_id' => '123456789',
            'visit_type' => 'follow_up',
            'status_type' => 'lactating',
        ])['visit_type']);
    }

    // The prefill keeps this visit's own fields empty so they can be re-entered.

    public function test_prefill_leaves_the_status_and_measurements_empty(): void
    {
        $previous = PregnantLactatingWoman::factory()->create([
            'mother_id' => '123456789',
            'status_type' => 'pregnant',
            'full_name_ar' => 'اسم الأم',
            'husband_full_name' => 'اسم الزوج',
        ]);

        $page = new CreatePregnantLactatingWoman;
        $filled = null;
        $page->form = new class($filled) {
            public function __construct(private &$captured) {}

            public function fill(array $data): void
            {
                $this->captured = $data;
            }
        };

        $page->fillMotherDataFromAlert(['data' => [
            'mother_id' => $previous->mother_id,
            'full_name_ar' => $previous->full_name_ar,
            'husband_full_name' => $previous->husband_full_name,
        ]]);

        // The stable data comes across...
        $this->assertSame('اسم الأم', $filled['full_name_ar']);
        $this->assertSame('اسم الزوج', $filled['husband_full_name']);

        // ...while this visit's own fields stay empty for a fresh entry.
        foreach (['status_type', 'newborn_dob', 'weight_kg', 'height_cm', 'muac_mm', 'date_of_reporting'] as $field) {
            $this->assertNull($filled[$field], "{$field} must be left empty for the new visit.");
        }

        // No status picked yet, so the visit type waits inside the follow-up loop.
        $this->assertSame('follow_up', $filled['visit_type']);
    }

    // Editing: correcting a wrongly picked status re-derives the visit type.

    /**
     * A visit carrying every field the form insists on, so saving it from the
     * edit page exercises the visit type and nothing else.
     */
    private function completeVisit(array $attributes = []): PregnantLactatingWoman
    {
        return PregnantLactatingWoman::factory()->create(array_merge([
            'mother_id' => '123456789',
            'full_name_ar' => 'اسم الأم',
            'phone_number' => '0591234567',
            'date_of_birth' => '1996-05-04',
            // Not married, so the husband fields stay optional.
            'status' => 'أرملة',
            'muac_mm' => 240,
            'governorate' => 'gaza',
            'municipality' => 'gaza',
            'neighbourhood' => 'El Shatee',
        ], $attributes));
    }

    private function editPage(PregnantLactatingWoman $record): Testable
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        return Livewire::actingAs($admin)->test(EditPregnantLactatingWoman::class, ['record' => $record->getKey()]);
    }

    public function test_correcting_the_status_while_editing_re_derives_the_visit_type(): void
    {
        // First visit: pregnant, so "new".
        $this->completeVisit(['status_type' => 'pregnant', 'visit_type' => 'new', 'date_of_reporting' => '2026-01-01']);

        // Second visit: pregnant picked by mistake, so it read as a follow up.
        $second = $this->completeVisit(['status_type' => 'pregnant', 'visit_type' => 'follow_up', 'date_of_reporting' => '2026-02-01']);

        // Correcting it to breastfeeding is a different care cycle: "new".
        $this->editPage($second)
            ->assertFormSet(['visit_type' => 'follow_up'])
            ->set('data.status_type', 'lactating')
            ->assertFormSet(['visit_type' => 'new'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('new', $second->refresh()->visit_type);
    }

    public function test_editing_without_touching_the_status_keeps_the_visit_type(): void
    {
        $this->completeVisit(['status_type' => 'pregnant', 'visit_type' => 'new', 'date_of_reporting' => '2026-01-01']);
        $second = $this->completeVisit(['status_type' => 'pregnant', 'visit_type' => 'follow_up', 'date_of_reporting' => '2026-02-01']);

        $this->editPage($second)
            ->set('data.full_name_ar', 'اسم مصحح')
            ->assertFormSet(['visit_type' => 'follow_up'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('follow_up', $second->refresh()->visit_type);
    }

    public function test_editing_the_only_visit_of_a_mother_stays_new(): void
    {
        // Compared against itself this record would look like an unchanged
        // status and drop to "follow up", so it has to be left out of the
        // lookup for the previous visit.
        $only = $this->completeVisit(['status_type' => 'pregnant', 'visit_type' => 'new', 'date_of_reporting' => '2026-01-01']);

        $this->editPage($only)
            ->set('data.status_type', 'lactating')
            ->assertFormSet(['visit_type' => 'new'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('new', $only->refresh()->visit_type);
    }

    // Husband data: required only while the mother is married.

    /**
     * @return array<string, array{0: ?string, 1: bool}>
     */
    public static function maritalStatusMatrix(): array
    {
        return [
            'married requires the husband data' => ['متزوجة', true],
            'widowed does not' => ['أرملة', false],
            'divorced does not' => ['مطلقة', false],
            'separated does not' => ['منفصلة', false],
            'missing husband does not' => ['الزوج مفقود', false],
            'no status yet does not' => [null, false],
        ];
    }

    #[DataProvider('maritalStatusMatrix')]
    public function test_husband_data_is_required_only_for_married_mothers(?string $status, bool $expected): void
    {
        $this->assertSame($expected, PregnantLactatingWomanResource::husbandDataIsRequired($status));
    }

    public function test_every_offered_marital_status_is_covered_by_the_rule(): void
    {
        $options = PregnantLactatingWomanResource::maritalStatusOptions();

        $this->assertArrayHasKey(PregnantLactatingWomanResource::MARRIED_STATUS, $options);
        $this->assertArrayHasKey('الزوج مفقود', $options);

        // Exactly one status - "married" - makes the husband data mandatory.
        $required = array_filter(
            array_keys($options),
            fn (string $status): bool => PregnantLactatingWomanResource::husbandDataIsRequired($status),
        );

        $this->assertSame([PregnantLactatingWomanResource::MARRIED_STATUS], array_values($required));
    }
}
