<?php

namespace App\Modules\Expenses\Models;

use App\Models\TenantModel as Model;
use App\Modules\Accounting\Models\TransactionType;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The company's own list, each mapped to the transaction type it posts as — docs/saas-launch-plan.md §14.3. */
class ExpenseCategory extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    protected $fillable = ['name', 'transaction_type_id', 'requires_receipt', 'is_active'];

    protected $casts = ['requires_receipt' => 'boolean', 'is_active' => 'boolean'];

    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ─── Replicate — docs/replicate-records.md ───

    public function replicationUniqueFields(): array
    {
        return ['name' => self::suggestCopyOf('name')];
    }
}
