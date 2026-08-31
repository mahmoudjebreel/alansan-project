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
