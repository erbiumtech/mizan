<?php

namespace App\Modules\PersonalFinance\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Keeps one person's records away from everybody else's.
 *
 * This is a global scope and a save/delete guard rather than a policy, and that
 * is not a style choice. AppServiceProvider::register() installs a Gate::before
 * that returns true for every ability except `create` when the user is a super
 * admin or holds the Administrator role:
 *
 *     if ($user->hasRole('Administrator') && $ability !== 'create') {
 *         return true;
 *     }
 *
 * so a policy method reading `$record->user_id === $user->id` never runs for
 * them — they would pass view, update and delete on anybody's records. The
 * codebase already met this on ExpenseClaim::assertNotOwnClaim(): "a rule that
 * has to hold for everyone cannot live in [a policy]".
 *
 * So:
 *   - the scope hides other people's rows from every query, with no bypass;
 *   - the guard refuses to write or delete somebody else's row, which is what
 *     actually stops an Administrator editing your books;
 *   - an Administrator's read-only cross-user view is an explicit opt-in through
 *     ownedByAnyone(), used on that one screen and nowhere else.
 *
 * Known limit, worth stating rather than discovering: App\Support\Impersonation
 * lets a company Administrator sign in *as* another user, and nothing keyed on
 * auth()->id() can see through that. Impersonated actions are recorded with
 * impersonated_by in the audit log.
 */
trait BelongsToOwner
{
    public static function bootBelongsToOwner(): void
    {
        static::addGlobalScope(new OwnerScope);

        static::creating(function (Model $model): void {
            $model->user_id ??= auth()->id();

            if ($model->user_id === null) {
                throw new RuntimeException(
                    static::class.' needs an owner. There is no signed-in user to attribute it to.'
                );
            }
        });

        // The half a policy cannot deliver. Without this an Administrator, who
        // passes every Gate check, could edit or delete another person's records
        // through a direct record URL even with the scope in place — the scope
        // hides rows from queries, it does not refuse writes.
        static::saving(fn (Model $model) => static::assertOwnedByCurrentUser($model, 'change'));
        static::deleting(fn (Model $model) => static::assertOwnedByCurrentUser($model, 'delete'));
    }

    private static function assertOwnedByCurrentUser(Model $model, string $verb): void
    {
        $actor = auth()->id();

        // No signed-in user means a seeder, a command or a queued job, none of
        // which are somebody snooping. The scope still applies to reads.
        if ($actor === null) {
            return;
        }

        $owner = $model->getOriginal('user_id') ?? $model->user_id;

        if ($owner !== null && (int) $owner !== (int) $actor) {
            throw new RuntimeException(
                "These are somebody else's personal records; you cannot {$verb} them."
            );
        }
    }

    /**
     * Drop the owner filter for a read that is meant to span everybody.
     *
     * The only intended caller is the Administrator's cross-user view, which is
     * gated on PersonalFinanceViewAny. Deliberately explicit and deliberately
     * not automatic: the point of the scope is that no query forgets it, so the
     * exception has to be typed out where it is used.
     */
    public static function ownedByAnyone(): Builder
    {
        return static::withoutGlobalScope(OwnerScope::class);
    }

    public function ownerId(): ?int
    {
        return $this->user_id === null ? null : (int) $this->user_id;
    }
}
