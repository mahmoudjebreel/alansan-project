{{--
    The unified trash listing.

    Two things this view must not do, both learned the hard way:

      * No Tailwind utility classes. The panel ships Filament's precompiled
        stylesheet - there is no Vite theme build here - so a utility Filament
        does not use itself simply does not exist and the markup renders
        unstyled. Everything visual is a `.ael-trash-*` class defined in
        resources/css/filament/admin/custom.css.

      * No Blade directives inside a component's attributes. Blade does not
        compile them there, so `@js(...)` in an `x-on:click` would be sent to
        the browser literally and the handler would be a syntax error. The
        Alpine expression is assembled in PHP below and bound as an
        expression, the same way the cache page does it.

    Every visible string comes from lang/*\/ui.php, and every badge from the
    module registry in App\Filament\Pages\Trash::modules().
--}}
<x-filament-panels::page>
    @php
        $rows = $this->getRows();
        $summary = $this->summary;
        $canRestore = auth()->user()?->can('trash.restore') ?? false;
        $canForceDelete = auth()->user()?->can('trash.force_delete') ?? false;
        $hasActions = $canRestore || $canForceDelete;

        $confirmJs = function (array $options, string $method, array $params): string {
            $arguments = array_map(
                fn ($argument): string => json_encode($argument, JSON_UNESCAPED_UNICODE),
                $params,
            );

            return 'confirmAction($wire, ' . json_encode($method) . ', ['
                . implode(', ', $arguments) . '], '
                . json_encode($options, JSON_UNESCAPED_UNICODE) . ')';
        };

        $cards = [
            [
                'label' => __('ui.trash.total'),
                'value' => $summary['total'],
                'icon' => 'heroicon-o-trash',
                'tone' => 'danger',
            ],
            [
                'label' => __('ui.trash.modules_affected'),
                'value' => $summary['modules'],
                'icon' => 'heroicon-o-squares-2x2',
                'tone' => 'info',
            ],
            [
                'label' => __('ui.trash.latest_deletion'),
                'value' => $summary['latest'] ?? __('ui.common.none'),
                'icon' => 'heroicon-o-clock',
                'tone' => 'warning',
            ],
        ];
    @endphp

    <div class="ael-trash">
        {{-- Summary of the whole trash, not of the page being looked at. --}}
        <div class="ael-trash-stats">
            @foreach ($cards as $card)
                <div class="ael-trash-stat">
                    <span class="ael-trash-stat__icon ael-trash-stat__icon--{{ $card['tone'] }}">
                        <x-filament::icon :icon="$card['icon']" />
                    </span>

                    <div class="ael-trash-stat__body">
                        <p class="ael-trash-stat__label">{{ $card['label'] }}</p>
                        <p class="ael-trash-stat__value">{{ $card['value'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <x-filament::section icon="heroicon-o-trash" icon-color="danger">
            <x-slot name="heading">
                {{ __('ui.trash.heading') }}
            </x-slot>

            <x-slot name="description">
                {{ __('ui.trash.description') }}
            </x-slot>

            @if ($rows->total() > 0)
                <div class="ael-trash-table-wrap">
                    <table class="ael-trash-table">
                        <thead>
                            <tr>
                                <th>{{ __('ui.trash.columns.module') }}</th>
                                <th>{{ __('ui.trash.columns.name') }}</th>
                                <th>{{ __('ui.trash.columns.identifier') }}</th>
                                <th>{{ __('ui.trash.columns.deleted_at') }}</th>
                                <th>{{ __('ui.trash.columns.deleted_by') }}</th>
                                @if ($hasActions)
                                    <th class="ael-trash-table__actions-head">{{ __('ui.trash.columns.actions') }}</th>
                                @endif
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($rows as $row)
                                <tr wire:key="trash-{{ $row['type'] }}-{{ $row['id'] }}">
                                    <td>
                                        <x-filament::badge :color="$row['color']" :icon="$row['icon']">
                                            {{ $row['module'] }}
                                        </x-filament::badge>
                                    </td>

                                    <td class="ael-trash-table__name">
                                        {{ $row['name'] ?: __('ui.common.none') }}
                                    </td>

                                    <td class="ael-trash-table__mono">
                                        {{ $row['identifier'] ?: __('ui.common.none') }}
                                    </td>

                                    <td class="ael-trash-table__muted ael-trash-table__mono">
                                        {{ $row['deleted_at']?->format('Y-m-d H:i') ?? __('ui.common.none') }}
                                    </td>

                                    <td class="ael-trash-table__muted">
                                        {{ $row['deleted_by'] ?: __('ui.common.none') }}
                                    </td>

                                    @if ($hasActions)
                                        <td>
                                            <div class="ael-trash-table__actions">
                                                @if ($canRestore)
                                                    <x-filament::button
                                                        tag="button"
                                                        size="xs"
                                                        color="success"
                                                        icon="heroicon-o-arrow-uturn-left"
                                                        :x-on:click="$confirmJs([
                                                            'title' => __('ui.trash.confirm_restore.title'),
                                                            'text' => __('ui.trash.confirm_restore.text'),
                                                            'icon' => 'question',
                                                            'confirmText' => __('ui.trash.confirm_restore.confirm'),
                                                            'successText' => __('ui.trash.confirm_restore.success'),
                                                            'errorText' => __('ui.trash.confirm_restore.error'),
                                                        ], 'restore', [$row['type'], $row['id']])"
                                                    >
                                                        {{ __('ui.trash.restore') }}
                                                    </x-filament::button>
                                                @endif

                                                @if ($canForceDelete)
                                                    <x-filament::button
                                                        tag="button"
                                                        size="xs"
                                                        color="danger"
                                                        icon="heroicon-o-trash"
                                                        :x-on:click="$confirmJs([
                                                            'title' => __('ui.trash.confirm_force_delete.title'),
                                                            'text' => __('ui.trash.confirm_force_delete.text'),
                                                            'icon' => 'warning',
                                                            'danger' => true,
                                                            'confirmText' => __('ui.trash.confirm_force_delete.confirm'),
                                                            'successText' => __('ui.trash.confirm_force_delete.success'),
                                                            'errorText' => __('ui.trash.confirm_force_delete.error'),
                                                        ], 'forceDelete', [$row['type'], $row['id']])"
                                                    >
                                                        {{ __('ui.trash.force_delete') }}
                                                    </x-filament::button>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($rows->hasPages())
                    <div class="ael-trash-pagination">
                        {{ $rows->links() }}
                    </div>
                @endif
            @else
                <div class="ael-trash-empty">
                    <span class="ael-trash-empty__icon">
                        <x-filament::icon icon="heroicon-o-trash" />
                    </span>

                    <p class="ael-trash-empty__title">{{ __('ui.trash.empty_title') }}</p>
                    <p class="ael-trash-empty__text">{{ __('ui.trash.empty_description') }}</p>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
