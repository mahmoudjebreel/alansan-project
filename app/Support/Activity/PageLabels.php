<?php

namespace App\Support\Activity;

use Filament\Facades\Filament;
use Throwable;

/**
 * Turns a panel route name into something a person can read, and back into
 * the model a resource page was showing.
 *
 * Built from the panel's own registered resources and pages, so a new module
 * is labelled correctly without anything being added here. Every lookup is
 * wrapped: a label that cannot be resolved falls back to the route name, and
 * a subject that cannot be resolved is simply not recorded.
 */
final class PageLabels
{
    /** @var array<string, array{resources: array<string, array{label: string, model: string}>, pages: array<string, string>}> */
    private static array $maps = [];

    /**
     * The model class and record key a resource route was opened on.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public static function subjectFor(?string $routeName, mixed $record): array
    {
        if ($routeName === null) {
            return [null, null];
        }

        // A list or create page shows no single record, so it names no subject.
        if (! is_scalar($record) || ! is_numeric((string) $record)) {
            return [null, null];
        }

        foreach (self::map()['resources'] as $base => $entry) {
            if (str_starts_with($routeName, $base . '.')) {
                return [$entry['model'], (int) $record];
            }
        }

        return [null, null];
    }

    /** list, view, edit, create, dashboard or page. */
    public static function kindFor(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        if (str_ends_with($routeName, '.pages.dashboard')) {
            return 'dashboard';
        }

        $last = substr($routeName, (int) strrpos($routeName, '.') + 1);

        return match ($last) {
            'index' => 'list',
            'view' => 'view',
            'edit' => 'edit',
            'create' => 'create',
            default => 'page',
        };
    }

    public static function labelFor(?string $routeName, ?string $pageKind = null): string
    {
        if ($routeName === null || $routeName === '') {
            return '-';
        }

        $map = self::map();

        if (isset($map['pages'][$routeName])) {
            return $map['pages'][$routeName];
        }

        foreach ($map['resources'] as $base => $entry) {
            if (str_starts_with($routeName, $base . '.')) {
                $kind = $pageKind ?? self::kindFor($routeName);
                $kindLabel = __("ui.user_activity.kinds.{$kind}");

                return $entry['label'] . ' - ' . $kindLabel;
            }
        }

        return $routeName;
    }

    /**
     * Route name to label and model, cached per locale for the request.
     *
     * @return array{resources: array<string, array{label: string, model: string}>, pages: array<string, string>}
     */
    private static function map(): array
    {
        $locale = app()->getLocale();

        if (isset(self::$maps[$locale])) {
            return self::$maps[$locale];
        }

        $resources = [];
        $pages = [];

        try {
            $panel = Filament::getCurrentOrDefaultPanel();

            if ($panel !== null) {
                foreach ($panel->getResources() as $resource) {
                    try {
                        $resources[$resource::getRouteBaseName($panel)] = [
                            'label' => (string) $resource::getPluralModelLabel(),
                            'model' => (string) $resource::getModel(),
                        ];
                    } catch (Throwable) {
                        // A resource that cannot describe itself is listed by route name.
                    }
                }

                foreach ($panel->getPages() as $page) {
                    try {
                        $pages[$page::getRouteName($panel)] = (string) $page::getNavigationLabel();
                    } catch (Throwable) {
                        // Same fallback.
                    }
                }
            }
        } catch (Throwable) {
            // No panel in this context (console, tests without the panel booted).
        }

        return self::$maps[$locale] = ['resources' => $resources, 'pages' => $pages];
    }

    /** Test seam: forget the cached map. */
    public static function flush(): void
    {
        self::$maps = [];
    }
}
