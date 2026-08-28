<?php

namespace App\Support\Notifications;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps a model onto the module a Super Admin sees in a notification: its
 * Arabic name, how one of its records is identified, and where to look at it.
 *
 * The six data modules are the ones the notification system is about. User,
 * Role and FollowUpChildVisit are listed too because the audit notifications
 * that preceded this system already covered them, and dropping them would be
 * a silent regression.
 */
final class NotifiableModule
{
    /**
     * class-basename => [label, resource class or null, identifying attributes]
     *
     * @var array<string, array{label: string, resource: ?string, keys: array<string>}>
     */
    private const MAP = [
        'Child' => [
            'label' => 'الأطفال',
            'resource' => \App\Filament\Resources\ChildResource::class,
            'keys' => ['child_id', 'name'],
        ],
        'PregnantLactatingWoman' => [
            'label' => 'الحوامل والمرضعات',
            'resource' => \App\Filament\Resources\PregnantLactatingWomanResource::class,
            'keys' => ['mother_id', 'full_name_ar'],
        ],
        'GroupSession' => [
            'label' => 'الجلسات الجماعية',
            'resource' => \App\Filament\Resources\GroupSessionResource::class,
            'keys' => ['id_number', 'full_name_ar'],
        ],
        'MotherToMotherSession' => [
            'label' => 'جلسات أم لأم',
            'resource' => \App\Filament\Resources\MotherToMotherResource::class,
            'keys' => ['id_number', 'full_name_ar'],
        ],
        'IndividualCounseling' => [
            'label' => 'جلسات الإرشاد الفردي',
            'resource' => \App\Filament\Resources\IndividualCounselingResource::class,
            'keys' => ['mother_id_number', 'child_name'],
        ],
        'FollowUpChild' => [
            'label' => 'متابعة الأطفال',
            'resource' => \App\Filament\Resources\FollowUpChildResource::class,
            'keys' => ['id_number', 'child_name'],
        ],
        'FollowUpChildVisit' => [
            'label' => 'زيارات متابعة الأطفال',
            'resource' => null,
            'keys' => ['visit_number'],
        ],
        'User' => [
            'label' => 'المستخدمين',
            'resource' => \App\Filament\Resources\UserResource::class,
            'keys' => ['name', 'email'],
        ],
        'Role' => [
            'label' => 'الأدوار والصلاحيات',
            'resource' => \App\Filament\Resources\RoleResource::class,
            'keys' => ['name'],
        ],
    ];

    /**
     * The six data modules, by module key, for the log page filter.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $meta): string => $meta['label'], self::MAP);
    }

    public static function labelFor(Model | string $model): string
    {
        $key = self::keyFor($model);

        return self::MAP[$key]['label'] ?? $key;
    }

    /**
     * The class basename, which is also the stable module key stored in the
     * notification payload.
     */
    public static function keyFor(Model | string $model): string
    {
        return class_basename(is_string($model) ? $model : $model::class);
    }

    /**
     * A short identifying label for one record, e.g. "123456789 - أحمد".
     */
    public static function recordLabel(Model $model): ?string
    {
        $key = self::keyFor($model);
        $parts = [];

        foreach (self::MAP[$key]['keys'] ?? [] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (filled($value) && is_scalar($value)) {
                $parts[] = (string) $value;
            }
        }

        return $parts === [] ? '#' . $model->getKey() : implode(' - ', $parts);
    }

    /**
     * A link to the affected record, when the module has a Filament resource
     * and the record can still be opened.
     *
     * A soft-deleted or force-deleted record has no viewable page, so those
     * deliberately return null rather than a link that 404s.
     */
    public static function referenceUrl(Model $model, string $action): ?string
    {
        if (in_array($action, [ActionType::DELETE, ActionType::FORCE_DELETE], true)) {
            return null;
        }

        $resource = self::MAP[self::keyFor($model)]['resource'] ?? null;

        if ($resource === null || ! class_exists($resource)) {
            return null;
        }

        try {
            $pages = $resource::getPages();
            $page = isset($pages['view']) ? 'view' : (isset($pages['edit']) ? 'edit' : null);

            return $page === null
                ? null
                : $resource::getUrl($page, ['record' => $model->getKey()]);
        } catch (\Throwable) {
            // A link is a nicety; never let it break the notification.
            return null;
        }
    }
}
