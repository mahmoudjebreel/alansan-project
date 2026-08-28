<?php

namespace App\Imports;

use App\Exports\AbstractTableExport;
use App\Exports\ChildrenExport;
use App\Exports\FollowUpChildrenExport;
use App\Exports\GroupSessionExport;
use App\Exports\IndividualCounselingExport;
use App\Exports\MotherToMotherExport;
use App\Exports\PregnantWomenExport;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use Illuminate\Database\Eloquent\Model;

/**
 * Central registry describing how each module is imported.
 *
 * The column structure always comes from the module's Export class, so the
 * downloadable template and the Excel export can never drift apart.
 */
final class ImportDefinition
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string<AbstractTableExport>  $export
     * @param  class-string  $resource  Filament resource, used to read the
     *                                  Select options and required fields that
     *                                  the manual Create form already enforces.
     * @param  array<string>  $computed  Fields the system derives itself. They are
     *                                   stripped from uploaded rows so a value in
     *                                   the file can never override the calculation.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $model,
        public readonly string $export,
        public readonly string $resource,
        public readonly string $permission,
        public readonly string $filename,
        public readonly array $computed = [],
    ) {
    }

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        static $definitions = null;

        return $definitions ??= collect([
            new self(
                key: 'children',
                label: __('fields.children'),
                model: Child::class,
                export: ChildrenExport::class,
                resource: \App\Filament\Resources\ChildResource::class,
                permission: 'children.import',
                filename: 'children',
                // FI is always re-derived from MUAC by the model.
                computed: ['fi'],
            ),
            new self(
                key: 'pregnant',
                label: __('fields.pregnant_lactating_women'),
                model: PregnantLactatingWoman::class,
                export: PregnantWomenExport::class,
                resource: \App\Filament\Resources\PregnantLactatingWomanResource::class,
                permission: 'pregnant.import',
                filename: 'pregnant-lactating-women',
                computed: ['fi'],
            ),
            new self(
                key: 'group_sessions',
                label: __('fields.group_sessions'),
                model: GroupSession::class,
                export: GroupSessionExport::class,
                resource: \App\Filament\Resources\GroupSessionResource::class,
                permission: 'group_sessions.import',
                filename: 'group-sessions',
            ),
            new self(
                key: 'mother_to_mother',
                label: __('fields.mother_to_mother_sessions'),
                model: MotherToMotherSession::class,
                export: MotherToMotherExport::class,
                resource: \App\Filament\Resources\MotherToMotherResource::class,
                permission: 'mother_to_mother.import',
                filename: 'mother-to-mother-sessions',
            ),
            new self(
                key: 'individual_counseling',
                label: __('fields.individual_counselings'),
                model: IndividualCounseling::class,
                export: IndividualCounselingExport::class,
                resource: \App\Filament\Resources\IndividualCounselingResource::class,
                permission: 'individual_counseling.import',
                filename: 'individual-counselings',
                // MUAC degree is always re-derived from MUAC by the model.
                computed: ['muac_degree'],
            ),
            new self(
                key: 'follow_up_children',
                label: __('fields.follow_up_children'),
                model: FollowUpChild::class,
                export: FollowUpChildrenExport::class,
                resource: \App\Filament\Resources\FollowUpChildResource::class,
                permission: 'follow_up_children.import',
                filename: 'follow-up-children',
                // Age at admission is derived from DOB + admission date.
                computed: ['age_at_admission'],
            ),
        ])->keyBy('key')->all();
    }

    public static function get(string $key): self
    {
        return static::all()[$key] ?? throw new \InvalidArgumentException("Unknown import module [{$key}].");
    }

    /**
     * A fresh export instance, used purely to read the column definitions.
     */
    public function exporter(): AbstractTableExport
    {
        /** @var class-string<Model> $model */
        $model = $this->model;

        return new ($this->export)($model::query());
    }

    /**
     * Import columns: every exported field except the system-computed ones.
     *
     * @return array<string>
     */
    public function fields(): array
    {
        return array_values(array_diff($this->exporter()->fields(), $this->computed));
    }

    public function booleanFields(): array
    {
        return $this->exporter()->booleanFields();
    }

    public function enumFields(): array
    {
        return $this->exporter()->enumFields();
    }

    /**
     * The module key the Super Admin notifications use, which is the model's
     * class basename rather than this registry's snake_case key.
     */
    public function moduleKeyForNotifications(): string
    {
        return class_basename($this->model);
    }

    /**
     * Whether this module carries the repeatable Follow Up visit columns.
     */
    public function hasVisits(): bool
    {
        return $this->model === FollowUpChild::class;
    }
}
