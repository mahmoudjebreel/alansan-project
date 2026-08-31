<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\ChildResource\Pages\EditChild;
use App\Filament\Resources\GroupSessionResource\Pages\CreateGroupSession;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages\CreatePregnantLactatingWoman;
use App\Models\Child;
use App\Models\GroupSession;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ID numbers and Gaza mobile numbers begin with a zero.
 *
 * The identity and phone inputs used to be marked ->numeric(), which renders a
 * number input whose state is normalised as a number: 0591234567 was written to
 * the database as 591234567, and the row then failed its own ten-digit rule the
 * next time anybody opened it.
 */
class IdentityAndPhoneLeadingZeroTest extends TestCase
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

    public function test_a_child_id_and_phone_keep_their_leading_zero(): void
    {
        Livewire::test(CreateChild::class)
            ->fillForm([
                'child_id' => '012345678',
                'name' => 'طفل',
                'phone_number' => '0591234567',
                'sex' => 'male',
                'date_of_birth' => '2024-01-01',
                'muac_mm' => 130,
                'governorate' => 'gaza',
                'municipality' => 'gaza',
                'type_of_site' => 'El Qoqa',
                'mother_marital_status' => 'متزوجة',
                'date_of_reporting' => now()->format('Y-m-d'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $child = Child::firstOrFail();

        $this->assertSame('012345678', $child->child_id);
        $this->assertSame('0591234567', $child->phone_number);
    }

    /**
     * Reopening such a record must not fail validation on values the user never
     * touched - which is exactly what the stripped zero used to cause.
     */
    public function test_a_saved_child_with_leading_zeros_can_still_be_edited(): void
    {
        $child = Child::factory()->create([
            'child_id' => '012345678',
            'phone_number' => '0591234567',
            'municipality' => 'gaza',
            'type_of_site' => 'El Qoqa',
            'mother_marital_status' => 'متزوجة',
            'muac_mm' => 130,
            'date_of_reporting' => now()->subDays(5)->format('Y-m-d'),
        ]);

        Livewire::test(EditChild::class, ['record' => $child->getRouteKey()])
            ->fillForm(['name' => 'اسم محدث'])
            ->call('save')
            ->assertHasNoFormErrors();

        $child->refresh();

        $this->assertSame('اسم محدث', $child->name);
        $this->assertSame('0591234567', $child->phone_number);
        $this->assertSame('012345678', $child->child_id);
    }

    public function test_a_mother_id_and_husband_phone_keep_their_leading_zero(): void
    {
        Livewire::test(CreatePregnantLactatingWoman::class)
            ->fillForm([
                'mother_id' => '098765432',
                'full_name_ar' => 'سيدة',
                'phone_number' => '0567654321',
                'date_of_birth' => '1995-05-05',
                'status_type' => 'pregnant',
                'status' => 'متزوجة',
                'husband_full_name' => 'الزوج',
                'husband_id_number' => '011223344',
                'husband_phone' => '0599887766',
                'muac_mm' => 240,
                'governorate' => 'gaza',
                'municipality' => 'gaza',
                'neighbourhood' => 'El Shatee',
                'date_of_reporting' => now()->format('Y-m-d'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $woman = PregnantLactatingWoman::firstOrFail();

        $this->assertSame('098765432', $woman->mother_id);
        $this->assertSame('0567654321', $woman->phone_number);
        $this->assertSame('011223344', $woman->husband_id_number);
        $this->assertSame('0599887766', $woman->husband_phone);
    }

    public function test_a_group_session_id_and_phone_keep_their_leading_zero(): void
    {
        Livewire::test(CreateGroupSession::class)
            ->fillForm([
                'session_date' => now()->format('Y-m-d'),
                'session_group_number' => 'G1',
                'session_subject' => 'bf_support',
                'locality' => 'karamah',
                'shelter_name' => 'el_salam',
                'id_number' => '087654321',
                'full_name_ar' => 'مشاركة',
                'category' => 'grandmothers',
                'marital_status' => 'married',
                'phone_number' => '0561122334',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $session = GroupSession::firstOrFail();

        $this->assertSame('087654321', $session->id_number);
        $this->assertSame('0561122334', $session->phone_number);
    }
}
