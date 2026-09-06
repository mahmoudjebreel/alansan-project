@php
    $summary = $this->summary();
    $cards = [
        ['key' => 'total', 'color' => 'gray'],
        ['key' => 'normal', 'color' => 'success'],
        ['key' => 'mam', 'color' => 'warning'],
        ['key' => 'sam', 'color' => 'danger'],
        ['key' => 'unmeasured', 'color' => 'gray'],
        ['key' => 'eligible', 'color' => 'primary'],
    ];

    // Where every case currently stands, counted in the database rather than
    // added up from what happens to be on this page of the table.
    $statuses = $this->statusSummary();
    $counters = [
        ['key' => 'pending', 'color' => 'warning'],
        ['key' => 'active_follow_ups', 'color' => 'success'],
        ['key' => 'closed_cases', 'color' => 'gray'],
        ['key' => 'needs_review', 'color' => 'danger'],
        ['key' => 'missing_follow_up_muac', 'color' => 'danger'],
    ];
@endphp

<x-filament-panels::page>
    {{-- What the selected upload contained, before any decision is taken. --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ($cards as $card)
            <x-filament::section compact>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('ui.referral_center.summary.' . $card['key']) }}
                </div>
                <div @class([
                    'mt-1 text-2xl font-bold',
                    'text-gray-950 dark:text-white' => $card['color'] === 'gray',
                    'text-success-600 dark:text-success-400' => $card['color'] === 'success',
                    'text-warning-600 dark:text-warning-400' => $card['color'] === 'warning',
                    'text-danger-600 dark:text-danger-400' => $card['color'] === 'danger',
                    'text-primary-600 dark:text-primary-400' => $card['color'] === 'primary',
                ])>
                    {{ number_format($summary[$card['key']]) }}
                </div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- Where the cases stand: what is waiting, what is being treated, what
         has closed, and the two kinds of missing measurement. --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
        @foreach ($counters as $counter)
            <x-filament::section compact>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('ui.referral_center.counters.' . $counter['key']) }}
                </div>
                <div @class([
                    'mt-1 text-2xl font-bold',
                    'text-gray-950 dark:text-white' => $counter['color'] === 'gray',
                    'text-success-600 dark:text-success-400' => $counter['color'] === 'success',
                    'text-warning-600 dark:text-warning-400' => $counter['color'] === 'warning',
                    'text-danger-600 dark:text-danger-400' => $counter['color'] === 'danger',
                ])>
                    {{ number_format($statuses[$counter['key']]) }}
                </div>
            </x-filament::section>
        @endforeach
    </div>

    @unless ($this->hasRecordedBatches())
        {{-- Uploads made before this screen existed left no batch behind, and
             so did any upload whose batch failed to record. Saying so is
             better than letting the whole database read as "this upload". --}}
        <x-filament::section compact>
            <div class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-400">
                <x-filament::icon
                    icon="heroicon-o-information-circle"
                    class="mt-0.5 h-5 w-5 shrink-0 text-gray-400"
                />
                <span>{{ __('ui.referral_center.no_batch_notice') }}</span>
            </div>
        </x-filament::section>
    @endunless

    {{ $this->table }}
</x-filament-panels::page>
