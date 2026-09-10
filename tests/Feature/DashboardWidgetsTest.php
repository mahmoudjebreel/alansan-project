<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ChildNutritionStatusChart;
use App\Filament\Widgets\DisplacementDistributionChart;
use App\Filament\Widgets\GovernorateDistributionChart;
use App\Filament\Widgets\RecordsOverTimeChart;
use App\Filament\Widgets\StatsOverview;
use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use App\Services\DashboardAnalytics;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /**
     * A chart widget's dataset, read past the protected getData().
     *
     * @return array<string, mixed>
     */
    private function chartData(object $widget): array
    {
        return (fn (): array => $this->getData())->call($widget);
    }

    public function test_the_dashboard_opens_with_the_figures_and_has_no_account_card(): void
    {
        $this->actingAsRole('Super Admin');

        $widgets = array_values(Filament::getWidgets());

        $this->assertSame(StatsOverview::class, $widgets[0]);
        $this->assertSame(RecordsOverTimeChart::class, $widgets[1]);
        $this->assertNotContains(AccountWidget::class, $widgets);
        $this->assertContains(ChildNutritionStatusChart::class, $widgets);
        $this->assertContains(DisplacementDistributionChart::class, $widgets);
    }

    public function test_the_dashboard_page_renders_for_a_super_admin(): void
    {
        $this->actingAsRole('Super Admin');

        Child::factory()->create(['muac_mm' => 110, 'date_of_reporting' => now()->toDateString()]);
        PregnantLactatingWoman::factory()->create(['date_of_reporting' => now()->toDateString()]);

        $html = $this->get('/admin')->assertOk()->getContent();

        // Widgets load lazily, so the page carries their Livewire component
        // names rather than their content. The figures come first.
        $stats = strpos($html, 'app.filament.widgets.stats-overview');
        $trend = strpos($html, 'app.filament.widgets.records-over-time-chart');

        $this->assertNotFalse($stats);
        $this->assertNotFalse($trend);
        $this->assertLessThan($trend, $stats);
        $this->assertStringContainsString('app.filament.widgets.child-nutrition-status-chart', $html);
        $this->assertStringContainsString('app.filament.widgets.displacement-distribution-chart', $html);
        $this->assertStringNotContainsString('filament.widgets.account-widget', $html);

        // The grid widens to three columns on large screens.
        $this->assertStringContainsString('--cols-xl: repeat(3, minmax(0, 1fr))', $html);
    }

    public function test_the_dashboard_grid_has_three_columns_on_wide_screens(): void
    {
        $this->assertSame(['default' => 1, 'md' => 2, 'xl' => 3], (new Dashboard)->getColumns());
    }

    public function test_nutrition_status_counts_children_by_muac_band(): void
    {
        $this->actingAsRole('Super Admin');

        Child::factory()->create(['muac_mm' => 115]);   // SAM (at the cut-off)
        Child::factory()->create(['muac_mm' => 100]);   // SAM
        Child::factory()->create(['muac_mm' => 120]);   // MAM
        Child::factory()->create(['muac_mm' => 125]);   // Normal (at the cut-off)
        Child::factory()->create(['muac_mm' => 140]);   // Normal
        Child::factory()->create(['muac_mm' => null]);  // No reading: in no band

        $this->assertSame(['sam' => 2, 'mam' => 1, 'normal' => 2], DashboardAnalytics::nutritionStatus());

        $data = $this->chartData(Livewire::test(ChildNutritionStatusChart::class)->instance());

        $this->assertSame([2, 1, 2], $data['datasets'][0]['data']);
    }

    public function test_the_reporting_trend_buckets_by_day_and_by_month(): void
    {
        $this->actingAsRole('Super Admin');

        Child::factory()->count(2)->create(['date_of_reporting' => now()->toDateString()]);
        Child::factory()->create(['date_of_reporting' => now()->subDays(2)->toDateString()]);
        Child::factory()->create(['date_of_reporting' => now()->subMonths(2)->startOfMonth()->toDateString()]);

        $week = DashboardAnalytics::reportingTrend(Child::class, '7_days');

        $this->assertCount(7, $week['labels']);
        $this->assertSame(2, end($week['values']));
        $this->assertSame(3, array_sum($week['values']));

        $quarter = DashboardAnalytics::reportingTrend(Child::class, '3_months');

        $this->assertCount(3, $quarter['labels']);
        $this->assertSame([1, 0, 3], $quarter['values']);
    }

    public function test_the_stats_cover_both_modules_for_a_super_admin(): void
    {
        $this->actingAsRole('Super Admin');

        Child::factory()->create(['muac_mm' => 110, 'is_displaced' => true]);
        Child::factory()->create(['muac_mm' => 130, 'visit_type' => 'follow_up']);
        PregnantLactatingWoman::factory()->create(['status_type' => 'pregnant', 'is_displaced' => true]);

        Livewire::test(StatsOverview::class)
            ->assertSee(__('fields.total_children'))
            ->assertSee(__('dashboard.sam'))
            ->assertSee(__('fields.total_plw'))
            ->assertSee(__('dashboard.displaced_records'))
            ->assertSee(__('dashboard.new_and_follow_up', ['new' => 1, 'follow_up' => 1]))
            ->assertSee(__('dashboard.pregnant_and_lactating', ['pregnant' => 1, 'lactating' => 0]))
            ->assertSee(__('dashboard.not_displaced_count', ['count' => 1]));
    }

    public function test_the_governorate_chart_is_a_bar_chart_over_both_modules(): void
    {
        $this->actingAsRole('Super Admin');

        Child::factory()->count(2)->create(['governorate' => 'Gaza']);
        PregnantLactatingWoman::factory()->create(['governorate' => 'Rafah']);

        $data = $this->chartData(Livewire::test(GovernorateDistributionChart::class)->instance());

        $this->assertSame(['Gaza', 'Rafah'], $data['labels']);
        $this->assertSame([2, 0], $data['datasets'][0]['data']);
        $this->assertSame([0, 1], $data['datasets'][1]['data']);
    }
}
