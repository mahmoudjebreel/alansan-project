<x-filament-panels::page>
    @php
        $latestBackup = $this->getLatestBackup();
        $allBackups = $this->getAllBackupFiles();

        // Blade does not compile directives inside a component's attributes,
        // so `@js(...)` in an x-on:click would reach the browser verbatim and
        // break the handler. The expression is assembled here instead and
        // bound as PHP, the same way the cache page does it.
        $confirmDelete = fn (string $path): string => 'confirmAction($wire, "deleteBackup", ['
            . json_encode($path, JSON_UNESCAPED_UNICODE) . '], '
            . json_encode([
                'title' => __('ui.backups.confirm_delete.title'),
                'text' => __('ui.backups.confirm_delete.text'),
                'icon' => 'warning',
                'danger' => true,
                'confirmText' => __('ui.backups.confirm_delete.confirm'),
                'successText' => __('ui.backups.confirm_delete.success'),
                'errorText' => __('ui.backups.confirm_delete.error'),
            ], JSON_UNESCAPED_UNICODE) . ')';
    @endphp

    <div class="fi-page-content space-y-6">
        {{-- ============ Latest Backup Section ============ --}}
        <x-filament::section icon="heroicon-o-circle-stack" icon-color="primary">
            <x-slot name="heading">
                {{ __('ui.backups.latest_heading') }}
            </x-slot>

            <x-slot name="description">
                {{ __('ui.backups.latest_description') }}
            </x-slot>

            <x-slot name="headerEnd">
                @if($latestBackup)
                    <x-filament::button
                        tag="button"
                        size="sm"
                        color="success"
                        icon="heroicon-o-arrow-down-tray"
                        wire:click="downloadBackup('{{ addslashes($latestBackup['path']) }}')"
                    >
                        {{ __('ui.backups.download_latest', ['size' => $latestBackup['size']]) }}
                    </x-filament::button>
                @endif
            </x-slot>

            @if($latestBackup)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <x-filament::icon icon="heroicon-o-calendar" class="h-5 w-5 text-primary-500" />
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('ui.backups.datetime') }}</p>
                                <p class="font-semibold text-gray-900 dark:text-white">{{ $latestBackup['date'] }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <x-filament::icon icon="heroicon-o-server" class="h-5 w-5 text-success-500" />
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('ui.backups.size') }}</p>
                                <x-filament::badge color="success">{{ $latestBackup['size'] }}</x-filament::badge>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5 text-info-500" />
                            <div class="min-w-0">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('ui.backups.filename') }}</p>
                                <p class="truncate font-mono text-xs font-semibold text-gray-900 dark:text-white">{{ $latestBackup['name'] }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('ui.backups.none_yet') }}
                </div>
            @endif
        </x-filament::section>

        {{-- ============ All Backups Table Section ============ --}}
        <x-filament::section icon="heroicon-o-archive-box" icon-color="gray">
            <x-slot name="heading">
                {{ __('ui.backups.archive_heading') }} ({{ count($allBackups) }})
            </x-slot>

            <x-slot name="description">
                {{ __('ui.backups.archive_description') }}
            </x-slot>

            @if(count($allBackups) > 0)
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50/50 dark:bg-white/5">
                            <tr>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">{{ __('ui.backups.col_filename') }}</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">{{ __('ui.backups.col_created') }}</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">{{ __('ui.backups.col_size') }}</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-center font-semibold text-gray-950 dark:text-white">{{ __('ui.backups.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach($allBackups as $file)
                                <tr wire:key="backup-{{ md5($file['path']) }}" class="hover:bg-gray-50/50 dark:hover:bg-white/5">
                                    <td class="fi-ta-cell px-4 py-3 font-mono text-xs font-semibold text-gray-950 dark:text-white">
                                        <div class="flex items-center gap-2">
                                            <x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4 text-primary-500" />
                                            <span>{{ $file['name'] }}</span>
                                        </div>
                                    </td>

                                    <td class="fi-ta-cell px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
                                        {{ $file['date'] }}
                                    </td>

                                    <td class="fi-ta-cell px-4 py-3">
                                        <x-filament::badge color="success">{{ $file['size'] }}</x-filament::badge>
                                    </td>

                                    <td class="fi-ta-cell px-4 py-3">
                                        <div class="flex items-center justify-center gap-2">
                                            <x-filament::button
                                                tag="button"
                                                size="xs"
                                                color="success"
                                                icon="heroicon-o-arrow-down-tray"
                                                wire:click="downloadBackup('{{ addslashes($file['path']) }}')"
                                            >
                                                {{ __('ui.backups.download') }}
                                            </x-filament::button>

                                            <x-filament::button
                                                tag="button"
                                                size="xs"
                                                color="danger"
                                                icon="heroicon-o-trash"
                                                :x-on:click="$confirmDelete($file['path'])"
                                            >
                                                {{ __('ui.backups.delete') }}
                                            </x-filament::button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('ui.backups.archive_empty') }}
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
