<?php

namespace Tests\Feature;

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

/**
 * Editing a Pregnant / Lactating Women record re-derives its visit type.
 *
 * The visit type used to be settled once, on create, and kept as it was
 * whatever the edit form changed. A second visit entered as lactating by
 * mistake and corrected to pregnant therefore stayed "new", although the
 * mother's previous pregnant visit makes it a follow up.
 *
 * The rule itself does not change: it is the same resolver, measured against
 * the mother's latest other active visit, that create and import already use.
 * What is tested here is that the edit page runs it - live in the locked field
 * and, above all, on the server when saving - and that the record being
 * edited is left out of the history it is compared with.
 */
class PregnantWomanEditVisitTypeTest extends TestCase
{
    use RefreshDatabase;

    private const MOTHER_ID = '123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    /**
     * A record that passes every rule of the edit form as it is, so a save
     * only ever fails on what a test changed on purpose.
     */
    private function visit(string $statusType, string $dateOfReporting, array $overrides = []): PregnantLactatingWoman
    {
        return PregnantLactatingWoman::factory()->create(array_merge([
            'mother_id' => self::MOTHER_ID,
            'status_type' => $statusType,
            'date_of_reporting' => $dateOfReporting,
            'full_name_ar' => 'أم تجريبية',
            'phone_number' => '0599000000',
            'date_of_birth' => '1995-01-01',
            'status' => 'أرملة',
            'muac_mm' => 240,
            'governorate' => 'gaza',
            'municipality' => 'gaza',
            'neighbourhood' => 'El Shatee',
        ], $overrides));
    }

    private function edit(PregnantLactatingWoman $record): Testable
    {
        return Livewire::test(EditPregnantLactatingWoman::class, ['record' => $record->getKey()]);
    }

    // -----------------------------------------------------------------
    // 1. The reported case: lactating by mistake, corrected to pregnant
    // -----------------------------------------------------------------

    public function test_correcting_a_second_visit_to_pregnant_makes_it_a_follow_up(): void
    {
        $this->visit('pregnant', '2026-01-01');
        $second = $this->visit('lactating', '2026-03-01');

        $this->assertSame('new', $second->visit_type);

        $this->edit($second)
            ->fillForm(['status_type' => 'pregnant'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pregnant_lactating_women', [
            'id' => $second->id,
            'status_type' => 'pregnant',
            'visit_type' => 'follow_up',
        ]);
    }

    // -----------------------------------------------------------------
    // 2. The same record saved with its status untouched stays as it was
    // -----------------------------------------------------------------

    public function test_a_second_visit_kept_lactating_stays_new(): void
    {
        $this->visit('pregnant', '2026-01-01');
        $second = $this->visit('lactating', '2026-03-01');

        $this->edit($second)
            ->fillForm(['full_name_ar' => 'اسم مصحح'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pregnant_lactating_women', [
            'id' => $second->id,
            'status_type' => 'lactating',
            'visit_type' => 'new',
            'full_name_ar' => 'اسم مصحح',
        ]);
    }

    // -----------------------------------------------------------------
    // 3. A record is never its own previous visit
    // -----------------------------------------------------------------

    public function test_the_only_record_of_a_mother_does_not_compare_against_itself(): void
    {
        $only = $this->visit('lactating', '2026-03-01');

        $this->edit($only)
            ->fillForm(['status_type' => 'pregnant'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pregnant_lactating_women', [
            'id' => $only->id,
            'status_type' => 'pregnant',
            'visit_type' => 'new',
        ]);
    }

    public function test_the_only_record_of_a_mother_stays_new_when_saved_with_the_same_status(): void
    {
        $only = $this->visit('pregnant', '2026-03-01');

        $this->edit($only)
            ->fillForm(['full_name_ar' => 'اسم مصحح'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('pregnant_lactating_women', [
            'id' => $only->id,
            'visit_type' => 'new',
        ]);
    }

    // -----------------------------------------------------------------
    // 4. The locked field is never trusted on save
    // -----------------------------------------------------------------

    public function test_a_stale_visit_type_submitted_on_edit_cannot_override_the_resolver(): void
    {
        $this->visit('pregnant', '2026-01-01');
        $second = $this->visit('lactating', '2026-03-01');

        // Whatever the (disabled) field still holds or a tampered request
        // sends, the resolver's answer is what is stored.
        $this->edit($second)
            ->fillForm(['status_type' => 'pregnant', 'visit_type' => 'new'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('follow_up', $second->fresh()->visit_type);

        // ...and in the other direction too.
        $this->edit($second->fresh())
            ->fillForm(['status_type' => 'lactating', 'visit_type' => 'follow_up'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('new', $second->fresh()->visit_type);
    }

    public function test_the_edit_page_forces_the_visit_type_server_side(): void
    {
        $this->visit('pregnant', '2026-01-01');
        $second = $this->visit('lactating', '2026-03-01');

        $page = new EditPregnantLactatingWoman;
        $page->record = $second;
        $mutate = (new \ReflectionClass($page))->getMethod('mutateFormDataBeforeSave');
        $mutate->setAccessible(true);

        // Corrected to pregnant against a pregnant baseline -> follow up.
        $this->assertSame('follow_up', $mutate->invoke($page, [
            'mother_id' => self::MOTHER_ID,
            'visit_type' => 'new',
            'status_type' => 'pregnant',
        ])['visit_type']);

        // Left lactating against a pregnant baseline -> new.
        $this->assertSame('new', $mutate->invoke($page, [
            'mother_id' => self::MOTHER_ID,
            'visit_type' => 'follow_up',
            'status_type' => 'lactating',
        ])['visit_type']);
    }

    // -----------------------------------------------------------------
    // 5. Create is untouched
    // -----------------------------------------------------------------

    public function test_create_still_derives_the_visit_type_exactly_as_before(): void
    {
        $page = new CreatePregnantLactatingWoman;
        $mutate = (new \ReflectionClass($page))->getMethod('mutateFormDataBeforeCreate');
        $mutate->setAccessible(true);

        // First visit -> new, whatever the form claims.
        $this->assertSame('new', $mutate->invoke($page, [
            'mother_id' => self::MOTHER_ID,
            'visit_type' => 'follow_up',
            'status_type' => 'pregnant',
        ])['visit_type']);

        $this->visit('pregnant', '2026-01-01');

        // Same status as the latest active visit -> follow up...
        $this->assertSame('follow_up', $mutate->invoke($page, [
            'mother_id' => self::MOTHER_ID,
            'visit_type' => 'new',
            'status_type' => 'pregnant',
        ])['visit_type']);

        // ...and a switch opens a new cycle.
        $this->assertSame('new', $mutate->invoke($page, [
            'mother_id' => self::MOTHER_ID,
            'visit_type' => 'follow_up',
            'status_type' => 'lactating',
        ])['visit_type']);
    }

    // -----------------------------------------------------------------
    // 6. The status matrix is the same on edit as everywhere else
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function statusSwitchMatrix(): array
    {
        return [
            'pregnant to pregnant stays a follow up' => ['pregnant', 'pregnant', 'follow_up'],
            'pregnant to lactating is a new cycle' => ['pregnant', 'lactating', 'new'],
            'pregnant to pregnant + lactating is a new cycle' => ['pregnant', 'pregnant_lactating', 'new'],
            'lactating to lactating stays a follow up' => ['lactating', 'lactating', 'follow_up'],
            'lactating to pregnant is a new cycle' => ['lactating', 'pregnant', 'new'],
            'lactating to pregnant + lactating is a new cycle' => ['lactating', 'pregnant_lactating', 'new'],
            'pregnant + lactating to pregnant + lactating stays a follow up' => ['pregnant_lactating', 'pregnant_lactating', 'follow_up'],
            'pregnant + lactating to pregnant is a new cycle' => ['pregnant_lactating', 'pregnant', 'new'],
            'pregnant + lactating to lactating is a new cycle' => ['pregnant_lactating', 'lactating', 'new'],
        ];
    }

    #[DataProvider('statusSwitchMatrix')]
    public function test_the_resolver_matrix_is_unchanged(string $previous, string $current, string $expected): void
    {
        $this->visit($previous, '2026-01-01');

        $this->assertSame($expected, PregnantWomanDuplicateChecker::resolveVisitType(self::MOTHER_ID, $current));
    }

    #[DataProvider('statusSwitchMatrix')]
    public function test_an_edited_record_is_classified_by_the_same_matrix(string $previous, string $current, string $expected): void
    {
        $this->visit($previous, '2026-01-01');
        // Stored as a plain repeat of the baseline; the edit below is what
        // moves it to the status under test.
        $second = $this->visit($previous, '2026-03-01');

        $this->edit($second)
            ->fillForm(['status_type' => $current])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($expected, $second->fresh()->visit_type);
    }

    public function test_an_edited_record_is_measured_against_the_latest_other_active_visit_only(): void
    {
        $this->visit('lactating', '2026-01-01');
        $this->visit('pregnant', '2026-03-01');
        $third = $this->visit('lactating', '2026-05-01');

        // Trashed visits are not history.
        $this->visit('lactating', '2026-06-01')->delete();

        // Against the pregnant baseline, pregnant is a follow up...
        $this->edit($third)
            ->fillForm(['status_type' => 'pregnant'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('follow_up', $third->fresh()->visit_type);

        // ...and lactating opens a new cycle, the older lactating visit
        // notwithstanding.
        $this->edit($third->fresh())
            ->fillForm(['status_type' => 'lactating'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('new', $third->fresh()->visit_type);
    }

    // -----------------------------------------------------------------
    // 7. The locked field follows the status live, before saving
    // -----------------------------------------------------------------

    public function test_changing_the_status_on_the_edit_form_updates_the_visit_type_before_saving(): void
    {
        $this->visit('pregnant', '2026-01-01');
        $second = $this->visit('lactating', '2026-03-01');

        $this->edit($second)
            ->assertFormSet(['visit_type' => 'new'])
            ->fillForm(['status_type' => 'pregnant'])
            ->assertFormSet(['visit_type' => 'follow_up'])
            ->fillForm(['status_type' => 'lactating'])
            ->assertFormSet(['visit_type' => 'new']);

        // Nothing was saved by the live update alone.
        $this->assertSame('new', $second->fresh()->visit_type);
    }

    public function test_the_live_update_on_the_edit_form_does_not_compare_the_record_against_itself(): void
    {
        $only = $this->visit('lactating', '2026-03-01');

        $this->edit($only)
            ->fillForm(['status_type' => 'pregnant'])
            ->assertFormSet(['visit_type' => 'new'])
            ->fillForm(['status_type' => 'lactating'])
            ->assertFormSet(['visit_type' => 'new']);
    }
}
