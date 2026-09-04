<?php

namespace App\Filament\Pages;

use App\Exports\MealReport\MealReportExport;
use App\Services\MealReportService;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\SiteVocabulary;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * MEAL monthly monitoring report.
 *
 * Read-only: the page aggregates the existing modules for one month and one
 * site, previews the headline numbers, and exports the official three-sheet
 * workbook. It writes nothing.
 */
class MealReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?int $navigationSort = 19;

    protected string $view = 'filament.pages.meal-report';

    public static function getNavigationLabel(): string
    {
        return __('ui.meal_report.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.reports');
    }

    public function getTitle(): string
    {
        return __('ui.meal_report.title');
    }

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('meal_report.view') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'year' => now()->year,
            'month' => now()->month,
            // Left blank on purpose: a site must be chosen before exporting.
            'site' => null,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                \Filament\Schemas\Components\Section::make(__('fields.meal_report_filters'))
                    ->description(__('fields.meal_report_filters_hint'))
                    ->icon('heroicon-o-funnel')
                    ->schema([
                        Forms\Components\Select::make('year')
                            ->label(__('fields.meal_year'))
                            ->options(static::yearOptions())
                            ->required()
                            ->native(false)
                            ->live(),
                        Forms\Components\Select::make('month')
                            ->label(__('fields.meal_month'))
                            ->options(static::monthOptions())
                            ->required()
                            ->native(false)
                            ->live(),
                        Forms\Components\Select::make('site')
                            ->label(__('fields.meal_type_of_site'))
                            ->options(SiteVocabulary::options())
                            ->required()
                            ->native(false)
                            ->live()
                            ->helperText(__('fields.meal_site_required_hint'))
                            ->validationMessages([
                                'required' => __('fields.val_required', ['field' => __('fields.meal_type_of_site')]),
                            ]),
                    ])
                    ->columns(3),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportMealReport')
                ->label(__('fields.meal_export'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->authorize(fn (): bool => auth()->user()?->can('meal_report.export') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('meal_report.export') ?? false)
                // Blocked until a site is chosen; guarded again inside export().
                ->disabled(fn (): bool => ! $this->hasSite())
                ->action(fn (): ?BinaryFileResponse => $this->export()),
        ];
    }

    public function export(): ?BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('meal_report.export') ?? false, 403);

        if (! $this->hasSite()) {
            Notification::make()
                ->title(__('fields.meal_site_required'))
                ->body(__('fields.meal_site_required_hint'))
                ->danger()
                ->send();

            return null;
        }

        [$year, $month, $site] = $this->selection();

        $data = app(MealReportService::class)->build($year, $month, $site);

        $filename = sprintf(
            'meal-report-%04d-%02d-%s.xlsx',
            $year,
            $month,
            str($site === SiteVocabulary::ALL ? 'all-sites' : $site)->slug(),
        );

        return Excel::download(new MealReportExport($data), $filename);
    }

    /** True once the operator has actually picked something in the site field. */
    public function hasSite(): bool
    {
        return filled($this->data['site'] ?? null);
    }

    /**
     * Headline numbers for the on-screen preview.
     *
     * @return array<string, mixed>|null
     */
    public function previewSummary(): ?array
    {
        if (! $this->hasSite()) {
            return null;
        }

        [$year, $month, $site] = $this->selection();

        $data = app(MealReportService::class)->build($year, $month, $site);

        $sum = function (string $sheet, callable $matches) use ($data): int {
            $total = 0;

            foreach ($data[$sheet]['totals'] as $key => $value) {
                if (is_numeric($value) && $matches($key)) {
                    $total += (int) $value;
                }
            }

            return $total;
        };

        $screening = MealReportLayout::SHEET_SCREENING;
        $iycf = MealReportLayout::SHEET_IYCF;
        $cmam = MealReportLayout::SHEET_CMAM;

        return [
            'site' => SiteVocabulary::label($site),
            'period' => Carbon::create($year, $month, 1)->translatedFormat('F Y'),
            'sheets' => [
                [
                    'name' => $screening,
                    'days' => count($data[$screening]['rows']),
                    'metrics' => [
                        __('fields.meal_children_screened') => $sum($screening, fn (string $k): bool => str_starts_with($k, 'c6_23_') || str_starts_with($k, 'c24_59_')),
                        __('fields.meal_sam_cases') => $sum($screening, fn (string $k): bool => str_contains($k, '_sam_') && ! str_starts_with($k, 'pwd_')),
                        __('fields.meal_mam_cases') => $sum($screening, fn (string $k): bool => str_contains($k, '_mam_') && ! str_starts_with($k, 'pwd_')),
                        __('fields.meal_pbw_screened') => $sum($screening, fn (string $k): bool => (bool) preg_match('/^(pw|bf)_(new|fu)_/', $k)),
                    ],
                ],
                [
                    'name' => $iycf,
                    'days' => count($data[$iycf]['rows']),
                    'metrics' => [
                        __('fields.meal_caregivers_counselled') => $sum($iycf, fn (string $k): bool => str_starts_with($k, 'cg_')),
                        __('fields.meal_group_sessions') => $sum($iycf, fn (string $k): bool => $k === 'group_sessions'),
                        __('fields.meal_participants') => $sum($iycf, fn (string $k): bool => $k === 'participants_total'),
                    ],
                ],
                [
                    'name' => $cmam,
                    'days' => count($data[$cmam]['rows']),
                    'metrics' => [
                        __('fields.meal_mam_admissions') => $sum($cmam, fn (string $k): bool => str_starts_with($k, 'mam_adm_')),
                        __('fields.meal_sam_admissions') => $sum($cmam, fn (string $k): bool => str_starts_with($k, 'sam_adm_')),
                        __('fields.meal_discharges') => $sum($cmam, fn (string $k): bool => str_contains($k, '_dis_')),
                    ],
                ],
            ],
        ];
    }

    /**
     * Template columns this system has no source for, for the on-screen note.
     *
     * @return array<string, int>
     */
    public function unsupportedCounts(): array
    {
        return array_map(
            fn (array $keys): int => count($keys),
            array_filter(MealReportService::unsupportedColumns(), fn (array $keys): bool => $keys !== []),
        );
    }

    /**
     * @return array{0:int,1:int,2:string}
     */
    private function selection(): array
    {
        return [
            (int) ($this->data['year'] ?? now()->year),
            (int) ($this->data['month'] ?? now()->month),
            (string) $this->data['site'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function yearOptions(): array
    {
        $years = [];

        for ($year = now()->year; $year >= now()->year - 5; $year--) {
            $years[$year] = (string) $year;
        }

        return $years;
    }

    /**
     * @return array<int, string>
     */
    public static function monthOptions(): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = Carbon::create(null, $month, 1)->translatedFormat('F');
        }

        return $months;
    }
}
