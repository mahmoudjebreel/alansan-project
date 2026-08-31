<x-filament-panels::page>
    @php
        $cacheTypes = $this->cacheTypes();

        // Blade directives are not compiled inside component attributes, so the
        // Alpine expression is assembled here and bound as a PHP expression.
        $confirmJs = function (array $options, string $method, array $params = []): string {
            $arguments = array_map(
                fn ($argument): string => json_encode($argument, JSON_UNESCAPED_UNICODE),
                array_merge([$method], $params),
            );

            return 'window.dashboardConfirm(' . json_encode($options, JSON_UNESCAPED_UNICODE) . ')'
                . '.then((result) => { if (result.isConfirmed) { $wire.call('
                . implode(', ', $arguments)
                . ') } })';
        };
    @endphp

    <div class="fi-page-content space-y-6">
        {{-- ============ Clear All Section ============ --}}
        <x-filament::section icon="heroicon-o-bolt" icon-color="danger">
            <x-slot name="heading">
                مسح كل أنواع الكاش دفعة واحدة
            </x-slot>

            <x-slot name="description">
                ينفّذ جميع عمليات المسح بالتسلسل، بما فيها كاش الصلاحيات والأدوار.
            </x-slot>

            <x-slot name="afterHeader">
                <x-filament::button
                    tag="button"
                    size="sm"
                    color="danger"
                    icon="heroicon-o-bolt"
                    :x-on:click="$confirmJs([
                        'title' => 'مسح كل أنواع الكاش',
                        'text' => 'سيتم مسح كاش التطبيق والإعدادات والفيوهات والروابط والصلاحيات. هل تريد المتابعة؟',
                        'icon' => 'warning',
                        'danger' => true,
                        'confirmText' => 'نعم، امسح الكل',
                    ], 'clearAll')"
                >
                    مسح كل شيء
                </x-filament::button>
            </x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                استخدم هذا الزر بعد تشغيل أي Seeder أو بعد تعديل الأدوار والصلاحيات مباشرة من قاعدة البيانات،
                ليصبح التغيير سارياً فوراً على كل المستخدمين.
            </p>
        </x-filament::section>

        {{-- ============ Individual Cache Types ============ --}}
        <x-filament::section icon="heroicon-o-squares-2x2" icon-color="gray">
            <x-slot name="heading">
                مسح نوع محدد من الكاش
            </x-slot>

            <x-slot name="description">
                اختر النوع الذي تريد مسحه فقط، دون التأثير على باقي الأنواع.
            </x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach($cacheTypes as $key => $type)
                    <div
                        wire:key="cache-type-{{ $key }}"
                        class="flex flex-col justify-between gap-4 rounded-xl bg-gray-50 p-4 dark:bg-white/5"
                    >
                        <div class="flex items-start gap-3">
                            <x-filament::icon
                                :icon="$type['icon']"
                                @class([
                                    'h-5 w-5 shrink-0',
                                    'text-primary-500' => $type['color'] === 'primary',
                                    'text-info-500' => $type['color'] === 'info',
                                    'text-warning-500' => $type['color'] === 'warning',
                                    'text-success-500' => $type['color'] === 'success',
                                    'text-gray-500' => $type['color'] === 'gray',
                                ])
                            />
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-white">{{ $type['label'] }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $type['description'] }}</p>
                                @if($type['command'])
                                    <p class="mt-2 font-mono text-xs text-gray-400 dark:text-gray-500">{{ $type['command'] }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <x-filament::button
                                tag="button"
                                size="xs"
                                :color="$type['color']"
                                icon="heroicon-o-trash"
                                :x-on:click="$confirmJs([
                                    'title' => 'مسح ' . $type['label'],
                                    'text' => 'هل أنت متأكد من مسح ' . $type['label'] . '؟',
                                    'icon' => 'warning',
                                    'danger' => true,
                                    'confirmText' => 'نعم، امسح',
                                ], 'clearCache', [$key])"
                            >
                                مسح
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
