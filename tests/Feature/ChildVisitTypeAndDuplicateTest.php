<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Models\Child;
use App\Support\ChildDuplicateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChildVisitTypeAndDuplicateTest extends TestCase
{
    use RefreshDatabase;

    /** MUAC values that classify to each FI band (SAM <= 115 < MAM < 125 <= Normal). */
    private const MUAC_FOR_FI = [
        'SAM' => 110,
        'MAM' => 120,
        'Normal' => 130,
    ];

    // Rule 1 - trashed records do not exist as far as duplicate checking goes.

    public function test_soft_deleted_child_is_not_treated_as_duplicate(): void
    {
        Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 110])->delete();

        $this->assertFalse(ChildDuplicateChecker::hasActiveVisit('123456789'));
        $this->assertNull(ChildDuplicateChecker::latestActiveVisit('123456789'));
        $this->assertSame('new', ChildDuplicateChecker::resolveVisitType('123456789', 110));
    }

    public function test_latest_active_visit_ignores_trashed_and_picks_most_recent(): void
    {
        Child::factory()->create(['child_id' => '123456789', 'date_of_reporting' => '2026-01-01']);
        $latest = Child::factory()->create(['child_id' => '123456789', 'date_of_reporting' => '2026-03-01']);
        Child::factory()->create(['child_id' => '123456789', 'date_of_reporting' => '2026-06-01'])->delete();

        $this->assertTrue($latest->is(ChildDuplicateChecker::latestActiveVisit('123456789')));
    }

    public function test_latest_active_visit_can_ignore_the_record_being_edited(): void
    {
        $child = Child::factory()->create(['child_id' => '123456789']);

        $this->assertNull(ChildDuplicateChecker::latestActiveVisit('123456789', $child));
    }

    // Rule 2 - a first visit is always "new".

    public function test_first_visit_is_always_new(): void
    {
        foreach (self::MUAC_FOR_FI as $muac) {
            $this->assertSame('new', ChildDuplicateChecker::resolveVisitType('987654321', $muac));
        }
    }

    // Rule 3 - the relapse check, one case per row of the specification table.

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function relapseMatrix(): array
    {
        return [
            'Normal to Normal stays a follow up' => ['Normal', 'Normal', 'follow_up'],
            'Normal to MAM is a relapse' => ['Normal', 'MAM', 'new'],
            'Normal to SAM is a relapse' => ['Normal', 'SAM', 'new'],
            'MAM to SAM is a deterioration' => ['MAM', 'SAM', 'new'],
            'MAM to MAM stays a follow up' => ['MAM', 'MAM', 'follow_up'],
            'MAM to Normal is an improvement' => ['MAM', 'Normal', 'follow_up'],
            'SAM to MAM is an improvement' => ['SAM', 'MAM', 'follow_up'],
            'SAM to Normal is a full improvement' => ['SAM', 'Normal', 'follow_up'],
            'SAM to SAM stays a follow up' => ['SAM', 'SAM', 'follow_up'],
        ];
    }

    #[DataProvider('relapseMatrix')]
    public function test_relapse_rule_decides_the_visit_type(string $previousFi, string $currentFi, string $expected): void
    {
        $previous = Child::factory()->create([
            'child_id' => '123456789',
            'muac_mm' => self::MUAC_FOR_FI[$previousFi],
            'date_of_reporting' => '2026-01-01',
        ]);

        $this->assertSame($previousFi, $previous->fi, 'The previous visit must classify as expected.');

        $this->assertSame(
            $expected,
            ChildDuplicateChecker::resolveVisitType('123456789', self::MUAC_FOR_FI[$currentFi]),
        );
    }

    public function test_severity_ranking_is_ordered_from_mildest_to_most_severe(): void
    {
        $this->assertSame(0, ChildDuplicateChecker::fiSeverity('Normal'));
        $this->assertSame(1, ChildDuplicateChecker::fiSeverity('MAM'));
        $this->assertSame(2, ChildDuplicateChecker::fiSeverity('SAM'));
        $this->assertNull(ChildDuplicateChecker::fiSeverity(null));
    }

    public function test_visit_stays_a_follow_up_until_a_muac_is_entered(): void
    {
        Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 130]);

        $this->assertSame('follow_up', ChildDuplicateChecker::resolveVisitType('123456789', null));
    }

    public function test_relapse_is_measured_against_the_latest_active_visit_only(): void
    {
        // An older SAM visit, then a Normal one: the Normal one is the baseline.
        Child::factory()->create([
            'child_id' => '123456789',
            'muac_mm' => 110,
            'date_of_reporting' => '2026-01-01',
        ]);
        Child::factory()->create([
            'child_id' => '123456789',
            'muac_mm' => 130,
            'date_of_reporting' => '2026-03-01',
        ]);

        $this->assertSame('new', ChildDuplicateChecker::resolveVisitType('123456789', 120));
    }

    // Server-side enforcement: the locked form field is never trusted.

    public function test_create_page_forces_visit_type_server_side(): void
    {
        $page = new CreateChild;
        $mutate = (new \ReflectionClass($page))->getMethod('mutateFormDataBeforeCreate');
        $mutate->setAccessible(true);

        // No record at all -> new, whatever the form claims.
        $this->assertSame('new', $mutate->invoke($page, [
            'child_id' => '123456789',
            'visit_type' => 'follow_up',
            'muac_mm' => 130,
        ])['visit_type']);

        // Soft-deleted record -> still new.
        Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 130])->delete();
        $this->assertSame('new', $mutate->invoke($page, [
            'child_id' => '123456789',
            'visit_type' => 'follow_up',
            'muac_mm' => 110,
        ])['visit_type']);

        // Active Normal record -> improvement or stability is a follow up...
        Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 130]);
        $this->assertSame('follow_up', $mutate->invoke($page, [
            'child_id' => '123456789',
            'visit_type' => 'new',
            'muac_mm' => 130,
        ])['visit_type']);

        // ...and a deterioration is a relapse.
        $this->assertSame('new', $mutate->invoke($page, [
            'child_id' => '123456789',
            'visit_type' => 'follow_up',
            'muac_mm' => 110,
        ])['visit_type']);
    }

    public function test_fi_stays_derived_from_the_current_muac_only(): void
    {
        $first = Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 110]);
        $this->assertSame('SAM', $first->fi);

        $second = Child::factory()->create([
            'child_id' => '123456789',
            'visit_type' => 'follow_up',
            'muac_mm' => 130,
        ]);

        $this->assertSame('Normal', $second->fi);
        $this->assertSame('SAM', $first->fresh()->fi);
    }
}
