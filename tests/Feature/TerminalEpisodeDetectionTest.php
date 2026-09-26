<?php

namespace Tests\Feature;

use App\Models\FollowUpChild;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * terminalEpisodes() - one query for every child - must decide exactly what
 * terminalEpisodeFor() decides one child at a time: the latest closed episode,
 * trash included, ordered by discharge date (an undated closure last) and
 * then by id, and terminal only when that episode ended as died.
 */
class TerminalEpisodeDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_bulk_and_the_single_reading_agree_on_every_history(): void
    {
        $histories = [
            // A single death.
            '470500001' => [['died', '2026-05-01', '2026-05-20']],
            // Died, then a later closure: not terminal.
            '470500002' => [['died', '2026-05-01', '2026-05-20'], ['cured', '2026-06-01', '2026-06-20']],
            // A closure, then the death: terminal.
            '470500003' => [['defaulted', '2026-03-01', '2026-03-20'], ['died', '2026-05-01', '2026-05-20']],
            // Equal discharge dates: the higher id wins - here the death.
            '470500004' => [['cured', '2026-05-01', '2026-05-20'], ['died', '2026-05-02', '2026-05-20']],
            // Equal discharge dates, the death first: not terminal.
            '470500005' => [['died', '2026-05-01', '2026-05-20'], ['cured', '2026-05-02', '2026-05-20']],
            // An undated death ranks after every dated closure: not terminal.
            '470500006' => [['died', '2026-05-01', null], ['non_responded', '2026-03-01', '2026-03-20']],
            // An undated death with no dated closure: terminal.
            '470500007' => [['died', '2026-05-01', null]],
            // Two undated closures: the higher id wins - the death.
            '470500008' => [['cured', '2026-03-01', null], ['died', '2026-05-01', null]],
            // An open episode after nothing closed: not terminal.
            '470500009' => [['under_follow_up', '2026-05-01', null]],
        ];

        foreach ($histories as $idNumber => $episodes) {
            foreach ($episodes as [$outcome, $admitted, $discharged]) {
                $this->episode($idNumber, $outcome, $admitted, $discharged);
            }
        }

        // A death moved to the trash still ends the history.
        $this->episode('470500010', 'died', '2026-05-01', '2026-05-20')->delete();
        // A later closure in the trash still outranks a death.
        $this->episode('470500011', 'died', '2026-05-01', '2026-05-20');
        $this->episode('470500011', 'cured', '2026-06-01', '2026-06-20')->delete();

        $bulk = FollowUpChild::terminalEpisodes();

        foreach ([...array_keys($histories), '470500010', '470500011'] as $idNumber) {
            $single = FollowUpChild::terminalEpisodeFor($idNumber);

            $this->assertSame($single !== null, isset($bulk[$idNumber]), "[{$idNumber}] terminal in one and not the other.");

            if ($single !== null) {
                $this->assertSame([
                    'admitted' => $single->admission_date?->format('Y-m-d'),
                    'died_on' => $single->discharge_date?->format('Y-m-d'),
                ], $bulk[$idNumber], "[{$idNumber}] dates.");
            }
        }

        $this->assertEqualsCanonicalizing(
            ['470500001', '470500003', '470500004', '470500007', '470500008', '470500010'],
            array_map('strval', array_keys($bulk)),
        );
    }

    public function test_the_bulk_reading_is_one_query(): void
    {
        foreach (range(1, 15) as $i) {
            $this->episode((string) (470600000 + $i), 'died', '2026-05-01', '2026-05-20');
        }

        \DB::enableQueryLog();
        \DB::flushQueryLog();

        $this->assertCount(15, FollowUpChild::terminalEpisodes());
        $this->assertCount(1, \DB::getQueryLog());

        \DB::disableQueryLog();
    }

    private function episode(string $idNumber, string $outcome, string $admitted, ?string $discharged): FollowUpChild
    {
        return FollowUpChild::create([
            'id_number' => $idNumber,
            'child_name' => 'Test child',
            'sex' => 'M',
            'dob' => '2025-01-01',
            'mobile_number' => '0599123456',
            'shelter_name' => 'Mosaab camp',
            'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => $admitted,
            'discharge_date' => $discharged,
            'discharge_outcome' => $outcome,
        ]);
    }
}
