<x-filament-panels::page>
    @php
        $latestBackup = $this->getLatestBackup();
        $allBackups = $this->getAllBackupFiles();
    @endphp

    <div class="fi-page-content space-y-6">
        {{-- ============ Latest Backup Section ============ --}}
        <x-filament::section icon="heroicon-o-circle-stack" icon-color="primary">
            <x-slot name="heading">
                أحدث نسخة احتياطية لقاعدة البيانات
            </x-slot>

            <x-slot name="description">
                حفظ وتنزيل النسخة الاحتياطية المباشرة بصيغة SQL لحماية بيانات المركز من الضياع.
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
                        تنزيل أحدث نسخة ({{ $latestBackup['size'] }})
                    </x-filament::button>
                @endif
            </x-slot>

            @if($latestBackup)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <x-filament::icon icon="heroicon-o-calendar" class="h-5 w-5 text-primary-500" />
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">تاريخ والتوقيت</p>
                                <p class="font-semibold text-gray-900 dark:text-white">{{ $latestBackup['date'] }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <x-filament::icon icon="heroicon-o-server" class="h-5 w-5 text-success-500" />
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">حجم الملف</p>
                                <x-filament::badge color="success">{{ $latestBackup['size'] }}</x-filament::badge>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5 text-info-500" />
                            <div class="min-w-0">
                                <p class="text-xs text-gray-500 dark:text-gray-400">اسم الملف</p>
                                <p class="truncate font-mono text-xs font-semibold text-gray-900 dark:text-white">{{ $latestBackup['name'] }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    لا توجد أي نسخة احتياطية حالياً. اضغط على الزر في الأعلى لإنشاء نسخة.
                </div>
            @endif
        </x-filament::section>

        {{-- ============ All Backups Table Section ============ --}}
        <x-filament::section icon="heroicon-o-archive-box" icon-color="gray">
            <x-slot name="heading">
                أرشيف النسخ الاحتياطية المحفوظة ({{ count($allBackups) }})
            </x-slot>

            <x-slot name="description">
                سجل متكامل بجميع الملفات المحفوظة للتنزيل أو الحذف النهائي.
            </x-slot>

            @if(count($allBackups) > 0)
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50/50 dark:bg-white/5">
                            <tr>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">اسم ملف النسخة (SQL)</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">تاريخ الإنشاء والتوقيت</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">حجم الملف</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-center font-semibold text-gray-950 dark:text-white">الإجراءات والتحكم</th>
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
                                                تحميل
                                            </x-filament::button>

                                            <x-filament::button
                                                tag="button"
                                                size="xs"
                                                color="danger"
                                                icon="heroicon-o-trash"
                                                x-on:click="confirmAction($wire, 'deleteBackup', ['{{ addslashes($file['path']) }}'], {
                                                    title: 'حذف النسخة الاحتياطية',
                                                    text: 'هل أنت متأكد من حذف هذه النسخة الاحتياطية؟ لا يمكن التراجع عن هذا الإجراء.',
                                                    icon: 'warning',
                                                    danger: true,
                                                    confirmText: 'نعم، احذف',
                                                    successText: 'تم حذف النسخة الاحتياطية بنجاح',
                                                    errorText: 'تعذّر حذف النسخة الاحتياطية'
                                                })"
                                            >
                                                حذف
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
                    لا توجد نسخ احتياطية محفوظة في الوقت الحالي.
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
