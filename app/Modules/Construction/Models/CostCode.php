<?php

namespace App\Modules\Construction\Models;

use App\Models\TenantModel as Model;
use App\Modules\Construction\Concerns\HasMaterialisedPath;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One code in the company's cost-code library: *what kind* of cost something is.
 *
 * `docs/construction-management-plan.md` §2. Per tenant and shared across every job (§2.2), because "what did
 * formwork to soffits cost us per square metre, across the last six jobs" is the question a contractor prices
 * the next tender with — and it is unanswerable the moment each job invents its own codes.
 *
 * The standard classifications are **columns, not sibling trees** (§2.1). Four trees would mean classifying
 * every cost four times, the four drifting, and a monthly reconciliation job for a person. One tree with
 * mapping columns means every report is a `group by`, the mapping is maintained once per code, and an unmapped
 * code is a countable row rather than a silently wrong total.
 */
class CostCode extends Model
{
    use Auditable;
    use HasMaterialisedPath;

    public const TYPE_LABOUR = 'labour';

    public const TYPE_MATERIAL = 'material';

    public const TYPE_PLANT = 'plant';

    public const TYPE_SUBCONTRACT = 'subcontract';

    public const TYPE_OTHER = 'other';

    /**
     * ICMS 3 Level 2 — the six cost categories, and the reason the report is comparable across countries.
     *
     * @var array<string, string>
     */
    public const ICMS_CATEGORIES = [
        'A' => 'Acquisition',
        'C' => 'Construction',
        'R' => 'Renewal',
        'O' => 'Operation',
        'M' => 'Maintenance',
        'E' => 'End of life',
    ];

    /**
     * The standards a code can be mapped to, and the column each lives in.
     *
     * Named once because the mapping screen, the import and every classified report read the same list, and
     * three copies would eventually disagree about whether OmniClass is in it.
     *
     * @var array<string, string>
     */
    public const MAPPINGS = [
        'masterformat_code' => 'MasterFormat (CSI)',
        'uniformat_code' => 'UniFormat',
        'uniclass_code' => 'Uniclass 2015 (NBS)',
        'omniclass_code' => 'OmniClass',
        'nrm_code' => 'NRM (RICS)',
    ];

    protected $table = 'construction_cost_codes';

    protected $fillable = [
        'parent_id', 'path', 'code', 'name', 'cost_type', 'unit',
        'is_leaf', 'is_active', 'sort_order',
        'masterformat_code', 'uniformat_code', 'uniclass_code', 'omniclass_code', 'nrm_code',
        'icms_category', 'icms_group', 'notes',
    ];

    protected $casts = [
        'is_leaf' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'cost_type' => self::TYPE_OTHER,
        'is_leaf' => true,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected static function booted(): void
    {
        static::created(fn (self $code) => $code->markParentAsBranch());

        // Also on a move, and the CSV import is why: it creates rows as roots and links parents in a second
        // pass, so without this an imported heading still looks bookable. See refreshLeafFlags().
        static::updated(function (self $code): void {
            if ($code->wasChanged('parent_id')) {
                $code->refreshLeafFlags();
            }
        });
    }

    /**
     * The library is company-wide, so a subtree is not partitioned.
     *
     * Deliberately unlike the WBS and locations: those belong to one job, and this one belongs to all of them,
     * which is §2.2's whole argument.
     */
    public static function pathScopeColumn(): ?string
    {
        return null;
    }

    /** What a job may actually book against: active, and not a heading. */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_leaf', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('cost_type', $type);
    }

    /**
     * Codes that carry no mapping for a standard.
     *
     * The report for that standard shows these as one "unmapped" row rather than dropping them, which is
     * §2.1's point: visible, countable, fixable. A silently short total is the failure being avoided.
     */
    public function scopeUnmappedFor(Builder $query, string $column): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, ''));
    }

    /** `03.30.00 Cast-in-place concrete` — how a code is referred to everywhere it appears. */
    public function label(): string
    {
        return trim("{$this->code} {$this->name}");
    }

    /** The ICMS category's name, or null when the code has not been mapped. */
    public function icmsCategoryName(): ?string
    {
        return self::ICMS_CATEGORIES[$this->icms_category] ?? null;
    }

    /**
     * Whether this code is mapped to every standard the company cares about.
     *
     * Takes the columns to check rather than assuming all of them, because a UK contractor maps NRM and
     * Uniclass and will never fill in MasterFormat — and a completeness figure that counted the ones it does
     * not use would read as permanently broken.
     *
     * @param  array<int, string>  $columns
     */
    public function isMappedFor(array $columns): bool
    {
        foreach ($columns as $column) {
            if (blank($this->{$column})) {
                return false;
            }
        }

        return true;
    }
}
