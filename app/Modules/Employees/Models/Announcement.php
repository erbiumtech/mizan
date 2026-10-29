<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Notifications\AnnouncementPublished;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Something the company tells its people — docs/saas-launch-plan.md §7.6.
 *
 * Aimed at everyone or at one department. Publishing is the act: it stamps
 * `published_at` and tells the audience over the channels the application
 * already has. Employees without a login hear nothing, which is honest — a
 * notice board on the wall is the other channel, and this is not it.
 */
class Announcement extends Model
{
    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_DEPARTMENT = 'department';

    /** One site's people — plan §7.9. `audience_value` is the location id. */
    public const AUDIENCE_LOCATION = 'location';

    protected $fillable = [
        'title', 'body', 'pinned', 'audience_kind', 'audience_value', 'published_at', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'pinned' => 'boolean',
        'published_at' => 'datetime',
        'expires_at' => 'date',
    ];

    protected $attributes = [
        'pinned' => false,
        'audience_kind' => self::AUDIENCE_ALL,
    ];

    /** Published and not yet expired: what the dashboard shows. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString()))
            ->orderByDesc('pinned')
            ->orderByDesc('published_at');
    }

    /** Aimed at this person: everyone, or their department. */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        $employee = Employee::query()->where('user_id', $user->getKey())->first(['department', 'location_id']);

        return $query->where(fn (Builder $q) => $q
            ->where('audience_kind', self::AUDIENCE_ALL)
            ->orWhere(fn (Builder $d) => $d
                ->where('audience_kind', self::AUDIENCE_DEPARTMENT)
                ->where('audience_value', $employee?->department ?? ''))
            ->orWhere(fn (Builder $l) => $l
                ->where('audience_kind', self::AUDIENCE_LOCATION)
                ->where('audience_value', (string) ($employee?->location_id ?? ''))));
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function audienceLabel(): string
    {
        return match ($this->audience_kind) {
            self::AUDIENCE_DEPARTMENT => (string) $this->audience_value,
            self::AUDIENCE_LOCATION => (string) (\App\Modules\Core\Models\WorkLocation::query()->whereKey($this->audience_value)->value('name') ?? 'A location'),
            default => 'Everyone',
        };
    }

    /**
     * The people this reaches: active employees with a login, all of them or
     * the department's.
     *
     * @return Collection<int, User>
     */
    public function audienceUsers(): Collection
    {
        $employees = Employee::query()->where('is_active', true)->whereNotNull('user_id');

        if ($this->audience_kind === self::AUDIENCE_DEPARTMENT) {
            $employees->where('department', (string) $this->audience_value);
        }

        if ($this->audience_kind === self::AUDIENCE_LOCATION) {
            $employees->where('location_id', (int) $this->audience_value);
        }

        return User::query()
            ->whereIn('id', $employees->pluck('user_id'))
            ->where('status', 1)
            ->get();
    }

    /**
     * Publish, once, and tell the audience.
     *
     * @return int how many people were told
     */
    public function publish(?User $by = null): int
    {
        if ($this->isPublished()) {
            throw new InvalidArgumentException('This announcement is already published.');
        }

        if ($this->audience_kind !== self::AUDIENCE_ALL && blank($this->audience_value)) {
            throw new InvalidArgumentException('Pick the department or location this is for, or aim it at everyone.');
        }

        $this->forceFill(['published_at' => now(), 'created_by' => $this->created_by ?? $by?->getKey()])->save();

        $audience = $this->audienceUsers();

        if ($audience->isNotEmpty()) {
            Notification::send($audience, new AnnouncementPublished($this));
        }

        return $audience->count();
    }
}
