<?php

namespace App\Exports;

use App\Support\Notifications\ActionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Excel export of the Super Admin notification log.
 *
 * Deliberately not an AbstractTableExport: that base class maps model columns
 * through the shared fields.* translations, while every value here lives
 * inside the notification's JSON payload.
 */
class NotificationLogExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $query)
    {
    }

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return array<string>
     */
    public function headings(): array
    {
        return [
            'التاريخ والوقت',
            'نوع الإجراء',
            'القسم',
            'الفاعل',
            'الدور',
            'السجل',
            'عدد السجلات',
            'الأولوية',
            'الحالة',
            'الرابط',
        ];
    }

    /**
     * @param  DatabaseNotification  $record
     * @return array<mixed>
     */
    public function map($record): array
    {
        $data = $record->data ?? [];
        $action = $data['action_type'] ?? null;

        return [
            $record->created_at?->format('Y-m-d H:i'),
            filled($action) ? ActionType::title($action) : null,
            $data['module_label'] ?? null,
            $data['actor_name'] ?? null,
            $data['actor_role'] ?? null,
            $data['record_label'] ?? null,
            $data['record_count'] ?? null,
            match ($data['priority'] ?? null) {
                'high' => 'مرتفعة',
                'medium' => 'متوسطة',
                'low' => 'منخفضة',
                default => null,
            },
            filled($record->read_at) ? 'مقروء' : 'غير مقروء',
            $data['reference_url'] ?? null,
        ];
    }
}
