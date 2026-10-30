<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Notifications\AnnouncementPublished;
use App\Modules\Employees\Rules\Rule;
use App\Modules\Employees\Rules\RuleEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;
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

    /** Whoever the rule matches when it is published — docs/saas-launch-plan.md §8.9. */
    public const AUDIENCE_RULE = 'rule';

    /** Everybody holding one role — §14.10. `audience_value` is the role name. */
    public const AUDIENCE_ROLE = 'role';

    /** One person — §14.10. `audience_value` is the employee id. */
    public const AUDIENCE_PERSON = 'person';

    /**
     * How loudly to say it — §14.10.
     *
     * Three, and no more. A priority list long enough to need thinking about is one
     * where everything ends up "high", which is the same as having none.
     */
    public const PRIORITIES = [
        'low' => 'For information',
        'normal' => 'Normal',
        'urgent' => 'Urgent',
    ];

    protected $fillable = [
        'title', 'body', 'pinned', 'audience_kind', 'audience_value', 'audience_rule', 'published_at', 'expires_at', 'created_by',
        // docs/saas-launch-plan.md §14.10.
        'category', 'priority', 'archived_at',
    ];

    protected $casts = [
        'pinned' => 'boolean',
        'published_at' => 'datetime',
        'expires_at' => 'date',
        'archived_at' => 'datetime',
    ];

    protected $attributes = [
        'pinned' => false,
        'audience_kind' => self::AUDIENCE_ALL,
        'priority' => 'normal',
    ];

    /** Published, not expired and not archived: what the dashboard shows. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query
            ->whereNull('archived_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString()))
            ->orderByDesc('pinned')
            ->orderByDesc('published_at');
    }

    /** Aimed at this person: everyone, or their department. */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        $employee = Employee::query()->where('user_id', $user->getKey())->first();

        // A rule is evaluated, not queried: the handful aimed by rule are judged for this person here.
        $byRule = $employee === null ? [] : static::query()->where('audience_kind', self::AUDIENCE_RULE)->get()
            ->filter(fn (Announcement $a): bool => app(RuleEvaluator::class)->matches($employee, $a->audience_rule))
            ->modelKeys();

        $roles = $user->getRoleNames()->all();

        return $query->where(fn (Builder $q) => $q
            ->where('audience_kind', self::AUDIENCE_ALL)
            ->orWhereIn('id', $byRule)
            ->orWhere(fn (Builder $d) => $d
                ->where('audience_kind', self::AUDIENCE_DEPARTMENT)
                ->where('audience_value', $employee?->department ?? ''))
            ->orWhere(fn (Builder $l) => $l
                ->where('audience_kind', self::AUDIENCE_LOCATION)
                ->where('audience_value', (string) ($employee?->location_id ?? '')))
            // §14.10. Roles are the landlord's and are read off the user rather than
            // joined, which is also what keeps this one query.
            ->orWhere(fn (Builder $r) => $r
                ->where('audience_kind', self::AUDIENCE_ROLE)
                ->whereIn('audience_value', $roles === [] ? [''] : $roles))
            ->orWhere(fn (Builder $p) => $p
                ->where('audience_kind', self::AUDIENCE_PERSON)
                ->where('audience_value', (string) ($employee?->getKey() ?? ''))));
    }

    /** Sanitised against the registries — docs/saas-launch-plan.md §8.9. */
    protected function audienceRule(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?array => ($rule = Rule::from(is_string($value) ? json_decode($value, true) : $value))->isEmpty() ? null : $rule->toArray(),
            set: fn (mixed $value): ?string => ($rule = Rule::from($value))->isEmpty() ? null : json_encode($rule->toArray()),
        );
    }

    /**
     * Out, and told — docs/saas-launch-plan.md §14.10.
     *
     * A date in the future is a schedule, not a publication: the dashboard does not
     * show it and nobody has been told, so it is still a thing somebody can change
     * their mind about. {@see isScheduled()} is the other half.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null && ! $this->published_at->isFuture();
    }

    public function isScheduled(): bool
    {
        return $this->published_at !== null && $this->published_at->isFuture();
    }

    public function audienceLabel(): string
    {
        return match ($this->audience_kind) {
            self::AUDIENCE_DEPARTMENT => (string) $this->audience_value,
            self::AUDIENCE_LOCATION => (string) (\App\Modules\Core\Models\WorkLocation::query()->whereKey($this->audience_value)->value('name') ?? 'A location'),
            self::AUDIENCE_RULE => 'By rule',
            self::AUDIENCE_ROLE => 'Everybody who is '.$this->audience_value,
            self::AUDIENCE_PERSON => (string) (Employee::query()->whereKey($this->audience_value)->first()?->display_label ?? 'One person'),
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
        if ($this->audience_kind === self::AUDIENCE_RULE) {
            return User::query()
                ->whereIn('id', app(RuleEvaluator::class)->matching($this->audience_rule)->pluck('user_id')->filter())
                ->where('status', 1)
                ->get();
        }

        $employees = Employee::query()->where('is_active', true)->whereNotNull('user_id');

        if ($this->audience_kind === self::AUDIENCE_DEPARTMENT) {
            $employees->where('department', (string) $this->audience_value);
        }

        if ($this->audience_kind === self::AUDIENCE_LOCATION) {
            $employees->where('location_id', (int) $this->audience_value);
        }

        if ($this->audience_kind === self::AUDIENCE_PERSON) {
            $employees->whereKey((int) $this->audience_value);
        }

        if ($this->audience_kind === self::AUDIENCE_ROLE) {
            // Roles live in the landlord and are scoped per company, so this asks the
            // permission layer rather than joining across databases.
            return User::query()
                ->whereIn('id', $employees->pluck('user_id'))
                ->where('status', 1)
                ->get()
                ->filter(fn (User $user): bool => $user->hasRole((string) $this->audience_value))
                ->values();
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
    public function publish(?User $by = null, ?Carbon $when = null): int
    {
        if ($this->isPublished()) {
            throw new InvalidArgumentException('This announcement is already published.');
        }

        if ($this->audience_kind === self::AUDIENCE_RULE ? $this->audience_rule === null : ($this->audience_kind !== self::AUDIENCE_ALL && blank($this->audience_value))) {
            throw new InvalidArgumentException('Pick who this is for, or aim it at everyone.');
        }

        /*
         * A date in the future publishes then, not now — docs/saas-launch-plan.md §14.10.
         *
         * `scopeCurrent` has always respected a future `published_at`; what was missing is
         * that nobody was told when it arrived. Stamping the chosen date and leaving the
         * telling to `announcements:publish` is what makes it a schedule rather than a
         * date that quietly does nothing.
         */
        $when ??= $this->published_at ?? now();

        $this->forceFill(['published_at' => $when, 'created_by' => $this->created_by ?? $by?->getKey()])->save();

        if ($when->isFuture()) {
            return 0;
        }

        $audience = $this->audienceUsers();

        if ($audience->isNotEmpty()) {
            Notification::send($audience, new AnnouncementPublished($this));
        }

        return $audience->count();
    }

    /**
     * Published, due, and nobody told yet — docs/saas-launch-plan.md §14.10.
     *
     * The nightly command's queue. `announced_at` would be a second column saying
     * almost the same thing as `published_at`; instead the command tells the audience
     * exactly once, on the first run after the date arrives, and the window it looks
     * back over is what makes that safe.
     */
    public function scopeDueToAnnounce(Builder $query, ?Carbon $asOf = null): Builder
    {
        $asOf ??= now();

        return $query
            ->whereNull('archived_at')
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$asOf->copy()->subDay(), $asOf]);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? 'Normal';
    }
}
