<?php

namespace Tests\Feature;

use App\Filament\Resources\GroupSessionResource;
use App\Filament\Resources\GroupSessionResource\Pages\CreateGroupSession;
use App\Models\GroupSession;
use App\Support\GroupSessionDuplicateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupSessionDuplicateAlertTest extends TestCase
{
    use RefreshDatabase;

    // Trashed sessions do not exist as far as duplicate checking goes.

    public function test_soft_deleted_session_is_not_treated_as_duplicate(): void
    {
        GroupSession::factory()->create(['id_number' => '412345678'])->delete();

        $this->assertFalse(GroupSessionDuplicateChecker::hasActiveSession('412345678'));
        $this->assertNull(GroupSessionDuplicateChecker::latestActiveSession('412345678'));
        $this->assertSame('new', GroupSessionDuplicateChecker::resolveVisitType('412345678'));
    }

    public function test_latest_active_session_ignores_trashed_and_picks_most_recent(): void
    {
        GroupSession::factory()->create(['id_number' => '412345678', 'session_date' => '2026-01-01']);
        $latest = GroupSession::factory()->create(['id_number' => '412345678', 'session_date' => '2026-03-01']);
        GroupSession::factory()->create(['id_number' => '412345678', 'session_date' => '2026-06-01'])->delete();

        $this->assertTrue($latest->is(GroupSessionDuplicateChecker::latestActiveSession('412345678')));
    }

    public function test_latest_active_session_can_ignore_the_record_being_edited(): void
    {
        $session = GroupSession::factory()->create(['id_number' => '412345678']);

        $this->assertNull(GroupSessionDuplicateChecker::latestActiveSession('412345678', $session));
    }

    public function test_blank_id_number_is_never_a_duplicate(): void
    {
        $this->assertNull(GroupSessionDuplicateChecker::latestActiveSession(null));
        $this->assertNull(GroupSessionDuplicateChecker::latestActiveSession(''));
    }

    // Visit type: presence of an active session is the whole decision.

    public function test_visit_type_is_new_without_an_active_session_and_follow_up_with_one(): void
    {
        $this->assertSame('new', GroupSessionDuplicateChecker::resolveVisitType('412345678'));

        GroupSession::factory()->create(['id_number' => '412345678']);

        $this->assertSame('follow_up', GroupSessionDuplicateChecker::resolveVisitType('412345678'));
    }

    public function test_create_page_forces_visit_type_server_side(): void
    {
        $page = new CreateGroupSession;
        $mutate = (new \ReflectionClass($page))->getMethod('mutateFormDataBeforeCreate');
        $mutate->setAccessible(true);

        // No record at all -> new, whatever the form claims.
        $this->assertSame('new', $mutate->invoke($page, [
            'id_number' => '412345678',
            'visit_type' => 'follow_up',
        ])['visit_type']);

        // Soft-deleted record -> still new.
        GroupSession::factory()->create(['id_number' => '412345678'])->delete();
        $this->assertSame('new', $mutate->invoke($page, [
            'id_number' => '412345678',
            'visit_type' => 'follow_up',
        ])['visit_type']);

        // Active record -> follow up.
        GroupSession::factory()->create(['id_number' => '412345678']);
        $this->assertSame('follow_up', $mutate->invoke($page, [
            'id_number' => '412345678',
            'visit_type' => 'new',
        ])['visit_type']);
    }

    // The payload carried into the new form by "fetch data".

    public function test_fetched_data_carries_the_participant_but_not_the_session(): void
    {
        $previous = GroupSession::factory()->create([
            'id_number' => '412345678',
            'full_name_ar' => 'أم أحمد',
            'session_date' => '2026-01-15',
            'session_group_number' => '7',
            'session_subject' => 'bf_support',
            'category' => 'pregnant',
            'newborn_dob' => '2026-05-01',
            'is_pwd' => true,
            'marital_status' => 'married',
            'phone_number' => '0599123456',
        ]);

        $data = GroupSessionResource::participantDataFrom($previous);

        $this->assertSame([
            'id_number' => '412345678',
            'full_name_ar' => 'أم أحمد',
            'locality' => 'tal_al_hawa',
            'shelter_name' => 'mosaab_camp',
            'category' => 'pregnant',
            'newborn_dob' => '2026-05-01',
            'is_pwd' => true,
            'marital_status' => 'married',
            'phone_number' => '0599123456',
            'has_gsfsh' => false,
            'receives_supplementary' => false,
        ], $data);

        // The session's own columns are never carried over.
        foreach (['session_date', 'session_group_number', 'session_subject', 'session_subject_other', 'visit_type'] as $sessionField) {
            $this->assertArrayNotHasKey($sessionField, $data);
        }
    }

    public function test_session_subject_label_falls_back_to_the_free_text_value(): void
    {
        $standard = GroupSession::factory()->create(['session_subject' => 'complimentary_feeding']);
        $this->assertSame(__('fields.complimentary_feeding'), GroupSessionResource::sessionSubjectLabel($standard));

        $other = GroupSession::factory()->create([
            'session_subject' => 'other',
            'session_subject_other' => 'التغذية أثناء الحمل',
        ]);
        $this->assertSame('التغذية أثناء الحمل', GroupSessionResource::sessionSubjectLabel($other));

        $otherBlank = GroupSession::factory()->create(['session_subject' => 'other']);
        $this->assertSame(__('fields.other'), GroupSessionResource::sessionSubjectLabel($otherBlank));
    }
}
