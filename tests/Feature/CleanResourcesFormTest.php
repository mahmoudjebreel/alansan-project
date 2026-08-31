<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Resources\GroupSessionResource;
use App\Filament\Resources\IndividualCounselingResource;
use App\Filament\Resources\MotherToMotherResource;
use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\UserResource;
use Filament\Schemas\Schema;
use Tests\TestCase;

class CleanResourcesFormTest extends TestCase
{
    public function test_all_resource_forms_render_schema_without_errors(): void
    {
        $schema = Schema::make();

        $this->assertNotEmpty(ChildResource::form($schema)->getComponents());
        $this->assertNotEmpty(FollowUpChildResource::form($schema)->getComponents());
        $this->assertNotEmpty(GroupSessionResource::form($schema)->getComponents());
        $this->assertNotEmpty(IndividualCounselingResource::form($schema)->getComponents());
        $this->assertNotEmpty(MotherToMotherResource::form($schema)->getComponents());
        $this->assertNotEmpty(PregnantLactatingWomanResource::form($schema)->getComponents());
        $this->assertNotEmpty(UserResource::form($schema)->getComponents());
        $this->assertNotEmpty(RoleResource::form($schema)->getComponents());
    }
}
