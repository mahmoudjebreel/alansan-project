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
            __('ui.notification_log.datetime'),
            __('ui.notification_log.action_type'),
            __('ui.notification_log.module'),
            __('ui.notification_log.actor'),
            __('ui.notification_log.role'),
            __('ui.notification_log.record'),
            __('ui.notification_log.record_count'),
            __('ui.notification_log.priority'),
            __('ui.notification_log.status'),
            __('ui.notification_log.link'),
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
                'high' => __('ui.notification_log.priority_high'),
                'medium' => __('ui.notification_log.priority_medium'),
                'low' => __('ui.notification_log.priority_low'),
                default => null,
            },
            filled($record->read_at)
                ? __('ui.notification_log.read')
                : __('ui.notification_log.unread'),
            $data['reference_url'] ?? null,
        ];
    }
}
