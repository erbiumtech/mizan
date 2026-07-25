<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;

class Company extends SpatieTenant
{
    protected $table = 'companies';

    protected $fillable = [
        'name',
        'slug',
        'database',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Users who may access this company (Filament tenant membership).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withTimestamps();
    }
}
