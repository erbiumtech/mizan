<?php

namespace App\Modules\Core\Models;

use App\Support\ModuleMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved table configuration (filters, toggled columns, sort, search) for a
 * given resource. Lives in the landlord database (it references users, who live
 * there) but is tagged with `company_id` and scoped to the current company, so
 * a user's views are isolated per company — mirroring App\Modules\Core\Models\ActivityLog.
 */
class TableView extends Model
{

    /**
     * Normalise on write: `resource` holds a Filament resource's stable alias, so
     * a saved view survives that resource moving into a module directory. Callers
     * may hand over `PayslipResource::class` directly.
     */
    public function setResourceAttribute(?string $value): void
    {
        $this->attributes['resource'] = $value === null ? null : ModuleMap::alias($value);
    }
    use HasFactory;

    protected $fillable = [
        'company_id', 'user_id', 'resource', 'name', 'icon', 'color',
        'is_favorite', 'is_public', 'is_global', 'is_default', 'state', 'sort',
    ];

    protected $casts = [
        'state' => 'array',
        'is_favorite' => 'boolean',
        'is_public' => 'boolean',
        'is_global' => 'boolean',
        'is_default' => 'boolean',
    ];

    /** Users live in the landlord DB — pin the connection so relations don't inherit the tenant one. */
    public function getConnectionName(): ?string
    {
        return config('multitenancy.landlord_database_connection_name') ?: config('database.default');
    }

    protected static function booted(): void
    {
        static::creating(function (self $view): void {
            $view->company_id ??= Company::current()?->getKey();
        });

        static::addGlobalScope('tenant', function (Builder $query): void {
            if ($companyId = Company::current()?->getKey()) {
                $query->where($query->getModel()->getTable().'.company_id', $companyId);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Views visible to a user for a resource: their own + public + global. */
    public function scopeVisibleTo(Builder $query, User $user, string $resource): Builder
    {
        return $query->where('resource', $resource)
            ->where(function (Builder $q) use ($user): void {
                $q->where('user_id', $user->getKey())
                    ->orWhere('is_public', true)
                    ->orWhere('is_global', true);
            });
    }
}
