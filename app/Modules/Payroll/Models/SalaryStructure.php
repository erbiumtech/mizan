<?php

namespace App\Modules\Payroll\Models;

use App\Models\TenantModel as Model;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A salary-structure template — docs/saas-launch-plan.md §14.1: components as a
 * share of gross, a fixed amount, or the balance. A form helper that fills a
 * package from one gross figure; the itemised package stays the payroll input.
 */
class SalaryStructure extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function lines(): HasMany
    {
        return $this->hasMany(SalaryStructureLine::class)->orderBy('sort');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The amounts for a gross figure, keyed by component code. Shares and fixed
     * lines first; the balance line takes what is left, never less than nothing.
     *
     * @return array<string, float>
     */
    public function amountsFor(float $gross): array
    {
        $amounts = [];
        $balanceCode = null;
        $allocated = 0.0;

        foreach ($this->lines()->with('component')->get() as $line) {
            $code = $line->component->code;

            if ($line->mode === SalaryStructureLine::MODE_BALANCE) {
                $balanceCode = $code;

                continue;
            }

            $amount = round($line->mode === SalaryStructureLine::MODE_FIXED ? (float) $line->value : $gross * (float) $line->value / 100, 2);
            $amounts[$code] = $amount;
            $allocated += $amount;
        }

        if ($balanceCode !== null) {
            $amounts[$balanceCode] = round(max(0, $gross - $allocated), 2);
        }

        return $amounts;
    }

    // ─── Replicate — docs/replicate-records.md ───

    public function replicationUniqueFields(): array
    {
        return ['name' => self::suggestCopyOf('name')];
    }

    public function replicationRelations(): array
    {
        return ['lines'];
    }
}
