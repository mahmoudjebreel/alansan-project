<x-filament-panels::page>
    <x-filament::tabs :label="__('ui.user_activity.title')">
        @foreach ($this->tabs() as $key => $tab)
            <x-filament::tabs.item
                :active="$activeTab === $key"
                :icon="$tab['icon']"
                wire:click="setTab('{{ $key }}')"
            >
                {{ $tab['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    @if ($activeTab === 'online')
        @livewire('user-activity.online-sessions', [], key('user-activity-online'))
    @elseif ($activeTab === 'sessions')
        @livewire('user-activity.sessions', [], key('user-activity-sessions'))
    @elseif ($activeTab === 'visits')
        @if ($sessionId)
            <div class="flex items-center gap-3">
                <x-filament::badge color="info">
                    {{ __('ui.user_activity.filters.session', ['id' => $sessionId]) }}
                </x-filament::badge>
                <x-filament::link tag="button" wire:click="clearSession" size="sm">
                    {{ __('ui.user_activity.filters.all_sessions') }}
                </x-filament::link>
            </div>
        @endif

        @livewire('user-activity.page-visits', ['sessionId' => $sessionId], key('user-activity-visits-' . ($sessionId ?? 'all')))
    @else
        <x-filament::section
            :heading="__('ui.user_activity.timeline.heading')"
            :description="__('ui.user_activity.timeline.hint')"
        >
            {{ $this->form }}
        </x-filament::section>

        @php($events = $this->timelineEvents())

        <x-filament::section>
            @if (empty($data['user_id']))
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('ui.user_activity.timeline.select_user') }}
                </p>
            @elseif ($events->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('ui.user_activity.timeline.no_events') }}
                </p>
            @else
                <ol class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($events as $event)
                        <li class="flex gap-4 py-3">
                            <div class="shrink-0 pt-0.5">
                                <x-filament::icon
                                    :icon="$event['icon']"
                                    @class([
                                        'h-5 w-5',
                                        'text-success-600 dark:text-success-400' => $event['color'] === 'success',
                                        'text-danger-600 dark:text-danger-400' => $event['color'] === 'danger',
                                        'text-warning-600 dark:text-warning-400' => $event['color'] === 'warning',
                                        'text-info-600 dark:text-info-400' => $event['color'] === 'info',
                                        'text-primary-600 dark:text-primary-400' => $event['color'] === 'primary',
                                        'text-gray-500 dark:text-gray-400' => $event['color'] === 'gray',
                                    ])
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $event['title'] }}
                                    </p>
                                    <time class="text-xs text-gray-500 dark:text-gray-400" dir="ltr">
                                        {{ $event['at']->timezone(\Filament\Support\Facades\FilamentTimezone::get())->format('Y-m-d H:i:s') }}
                                    </time>
                                </div>
                                @if ($event['meta'] !== [])
                                    <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                        @foreach ($event['meta'] as $label => $value)
                                            <div>
                                                <dt class="inline font-medium">{{ $label }}:</dt>
                                                <dd class="inline">{{ $value }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
