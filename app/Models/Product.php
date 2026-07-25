<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\TenantModel as Model;
use App\Traits\Auditable;

class Product extends Model
{
    use Auditable, HasCustomFields;

    public const METHOD_FIFO = 'fifo';

    public const METHOD_LIFO = 'lifo';

    public const METHOD_AVERAGE = 'average';

    protected $fillable = [
        'sku', 'name', 'description', 'unit', 'valuation_method', 'reorder_level',
        'inventory_account_id', 'cogs_account_id', 'revenue_account_id', 'is_active',
    ];

    protected $casts = [
        'reorder_level' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function inventoryAccount()
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function cogsAccount()
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }

    public function revenueAccount()
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }
}
