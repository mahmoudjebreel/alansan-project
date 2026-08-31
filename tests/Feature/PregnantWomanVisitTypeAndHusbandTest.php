<?php

namespace Tests\Feature;

use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages\CreatePregnantLactatingWoman;
use App\Models\PregnantLactatingWoman;
use App\Support\PregnantWomanDuplicateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        foreach (['pregnant', 'lactating'] as $statusType) {
            $this->assertSame('new', PregnantWomanDuplicateChecker::resolveVisitType('987654321', $statusType));
        }
    }

    // Rule 3 - switching between pregnant and lactating is a new care cycle.

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function statusSwitchMatrix(): array
    {
        return [
            'pregnant to lactating is a new cycle' => ['pregnant', 'lactating', 'new'],
            'lactating to pregnant is a new cycle' => ['lactating', 'pregnant', 'new'],
            'pregnant to pregnant stays a follow up' => ['pregnant', 'pregnant', 'follow_up'],
            'lactating to lactating stays a follow up' => ['lactating', 'lactating', 'follow_up'],
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
