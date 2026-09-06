<x-filament-panels::page>
    @php
        $summary = $this->previewSummary();
        $unsupported = $this->unsupportedCounts();
    @endphp

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @unless ($this->hasSite())
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">{{ __('fields.meal_site_required') }}</x-slot>
            <x-slot name="description">{{ __('fields.meal_site_required_hint') }}</x-slot>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-chart-bar" icon-color="primary">
            <x-slot name="heading">{{ __('fields.meal_preview') }}</x-slot>
            <x-slot name="description">
                {{ $summary['site'] }} &mdash; {{ $summary['period'] }}
                @if ($summary['months'] > 1)
                    <x-filament::badge color="info" size="sm" class="ms-2 inline-flex">
                        {{ trans_choice('fields.meal_months_in_file', $summary['months'], ['count' => $summary['months']]) }}
                    </x-filament::badge>
                @endif
            </x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($summary['sheets'] as $sheet)
                    <div class="fi-section rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $sheet['name'] }}
                            </h3>
                            <x-filament::badge :color="$sheet['days'] > 0 ? 'success' : 'gray'" size="sm">
                                {{ trans_choice('fields.meal_days_with_data', $sheet['days'], ['count' => $sheet['days']]) }}
                            </x-filament::badge>
                        </div>

                        <dl class="mt-3 space-y-2">
                            @foreach ($sheet['metrics'] as $label => $value)
                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <dt class="text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                                    <dd class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($value) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>

            @if (filled($summary['review']))
                {{-- Screenings with no usable measurement. They are deliberately
                     absent from every status column: an unmeasured child is not
                     a Normal one. --}}
                <div class="mt-4 rounded-xl bg-warning-50 p-4 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:ring-warning-400/30">
                    <h3 class="text-sm font-semibold text-warning-800 dark:text-warning-300">
                        {{ __('fields.meal_review_heading') }}
                    </h3>
                    <p class="mt-1 text-xs text-warning-700 dark:text-warning-400">
                        {{ __('fields.meal_review_hint') }}
                    </p>
                    <dl class="mt-3 space-y-2">
                        @foreach ($summary['review'] as $key => $count)
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <dt class="text-warning-700 dark:text-warning-400">{{ __('fields.meal_review_' . $key) }}</dt>
                                <dd class="font-semibold tabular-nums text-warning-900 dark:text-warning-200">{{ number_format($count) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </x-filament::section>
    @endunless

    @if (filled($unsupported))
        <x-filament::section
            icon="heroicon-o-information-circle"
            icon-color="gray"
            collapsible
            collapsed
        >
            <x-slot name="heading">{{ __('fields.meal_unsupported_heading') }}</x-slot>
            <x-slot name="description">{{ __('fields.meal_unsupported_hint') }}</x-slot>

            <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                @foreach ($unsupported as $sheet => $count)
                    <li class="flex items-center justify-between gap-3">
                        <span>{{ $sheet }}</span>
                        <x-filament::badge color="warning" size="sm">
                            {{ trans_choice('fields.meal_blank_columns', $count, ['count' => $count]) }}
                        </x-filament::badge>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
