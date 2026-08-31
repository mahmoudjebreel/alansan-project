<x-filament-panels::page>
    @php
        $rows = $this->getRows();
        $canRestore = auth()->user()?->can('trash.restore') ?? false;
        $canForceDelete = auth()->user()?->can('trash.force_delete') ?? false;

        $moduleColors = [
            'child' => 'primary',
            'pregnant_lactating_woman' => 'info',
            'individual_counseling' => 'warning',
            'mother_to_mother' => 'success',
            'group_session' => 'gray',
            'follow_up_child' => 'danger',
        ];
    @endphp

    <div class="fi-page-content space-y-6">
        <x-filament::section icon="heroicon-o-trash" icon-color="danger">
            <x-slot name="heading">
                سلة المحذوفات المركزية ({{ $rows->total() }})
            </x-slot>

            <x-slot name="description">
                استرجاع أية سجلات مُسحت بالخطأ وإعادتها لقوائمها الرئيسية، أو حذفها نهائياً.
            </x-slot>

            @if($rows->total() > 0)
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="bg-gray-50/50 dark:bg-white/5">
                            <tr>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">الوحدة التابعة</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">الاسم الكامل / العنوان</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">رقم الهوية / الرقم المرجعي</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">تاريخ الحذف</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-start font-semibold text-gray-950 dark:text-white">حُذف بواسطة</th>
                                <th class="fi-ta-header-cell px-4 py-3.5 text-center font-semibold text-gray-950 dark:text-white">الإجراءات والتحكم</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach($rows as $row)
                            
                                <tr wire:key="trash-{{ $row['type'] }}-{{ $row['id'] }}" class="hover:bg-gray-50/50 dark:hover:bg-white/5">

                                    <td class="fi-ta-cell px-4 py-3 whitespace-nowrap">

                                        <x-filament::badge :color="$moduleColors[$row['type']] ?? 'gray'">
                                            {{ $row['module'] }}
                                        </x-filament::badge>
                                    </td>

                                    <td class="fi-ta-cell px-4 py-3 font-bold text-gray-950 dark:text-white">
                                        {{ $row['name'] ?: '—' }}
                                    </td>

                                    <td class="fi-ta-cell px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-300">
                                        {{ $row['identifier'] ?: '—' }}
                                    </td>

                                    <td class="fi-ta-cell px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['deleted_at']?->format('Y-m-d H:i') ?? '—' }}
                                    </td>

                                    <td class="fi-ta-cell px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['deleted_by'] ?: '—' }}
                                    </td>

                                    <td class="fi-ta-cell px-4 py-3">
                                        <div class="flex items-center justify-center gap-2">
                                            @if($canRestore)
                                                <x-filament::button
                                                    tag="button"
                                                    size="xs"
                                                    color="success"
                                                    icon="heroicon-o-arrow-uturn-left"
                                                    x-on:click="confirmAction($wire, 'restore', ['{{ $row['type'] }}', {{ $row['id'] }}], {
                                                        title: 'استعادة السجل',
                                                        text: 'هل تريد استعادة هذا السجل وإرجاعه إلى قائمتك الرئيسية؟',
                                                        icon: 'question',
                                                        confirmText: 'نعم، استعِد السجل',
                                                        successText: 'تمت استعادة السجل بنجاح',
                                                        errorText: 'تعذّرت استعادة السجل'
                                                    })"
                                                >
                                                    استعادة
                                                </x-filament::button>
                                            @endif

                                            @if($canForceDelete)
                                                <x-filament::button
                                                    tag="button"
                                                    size="xs"
                                                    color="danger"
                                                    icon="heroicon-o-trash"
                                                    x-on:click="confirmAction($wire, 'forceDelete', ['{{ $row['type'] }}', {{ $row['id'] }}], {
                                                        title: 'حذف نهائي',
                                                        text: 'تحذير: سيتم حذف هذا السجل نهائياً من قاعدة البيانات ولا يمكن استرجاعه مطلقا. هل ترغب بالاستمرار؟',
                                                        icon: 'warning',
                                                        danger: true,
                                                        confirmText: 'نعم، احذف نهائياً',
                                                        successText: 'تم حذف السجل نهائياً',
                                                        errorText: 'تعذّر حذف السجل'
                                                    })"
                                                >
                                                    حذف نهائي
                                                </x-filament::button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($rows->hasPages())
                    <div class="mt-4 border-t border-gray-200 pt-4 dark:border-white/10">
                        {{ $rows->links() }}
                    </div>
                @endif
            @else
                <div class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    سلة المحذوفات فارغة حالياً.
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
