<?php

namespace App\Providers;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use App\Policies\ChildPolicy;
use App\Policies\FollowUpChildPolicy;
use App\Policies\GroupSessionPolicy;
use App\Policies\IndividualCounselingPolicy;
use App\Policies\MotherToMotherSessionPolicy;
use App\Policies\PregnantLactatingWomanPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Child::class => ChildPolicy::class,
        FollowUpChild::class => FollowUpChildPolicy::class,
        GroupSession::class => GroupSessionPolicy::class,
        IndividualCounseling::class => IndividualCounselingPolicy::class,
        MotherToMotherSession::class => MotherToMotherSessionPolicy::class,
        PregnantLactatingWoman::class => PregnantLactatingWomanPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function ($user, $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });
    }
}
