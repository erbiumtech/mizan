<?php

namespace App\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MPR extends Model
{
    use Auditable;
    use HasFactory;

    protected $table = 'mprs';

    protected $fillable = [
        'user_id',
        'mpr_date',
        'feedback',
        'topics_scope',
        'recent_module',
        'employee_request',
        'next_mpr_goal',
        'current_month_learning',
    ];

    // Data casting for date
    protected $casts = [
        'mpr_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
