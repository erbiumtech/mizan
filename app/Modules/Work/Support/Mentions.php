<?php

namespace App\Modules\Work\Support;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

/**
 * Who a piece of text mentions — docs/project-management-plan.md §10.3.
 *
 * **Matched on an id, never on a name.** `@[Ahmed Khan](user:12)` and the shorter `@user:12` both
 * name user 12; a bare `@Ahmed` names nobody, because a name is not a key and renaming somebody
 * must break nothing already written. The long form is what a picker inserts and what Markdown
 * renders as a link; the short form is what a person types when there is no picker, which in the
 * description's plain editor there is not.
 *
 * Only users who belong to the current company count: a comment cannot summon a stranger into a
 * company's work by typing a number.
 */
class Mentions
{
    private const PATTERN = '/@(?:\[[^\]]*\]\()?user:(\d+)\)?/';

    /**
     * The ids mentioned, in order of first appearance, members of this company only.
     *
     * @return Collection<int, int>
     */
    public static function in(?string $text): Collection
    {
        if (blank($text) || ! preg_match_all(self::PATTERN, $text, $matches)) {
            return collect();
        }

        $ids = collect($matches[1])->map(fn (string $id): int => (int) $id)->unique()->values();

        // The tenant middleware makes the company current on a request; inside the panel the tenant is
        // also Filament's. With neither there is no membership to check against, and nobody is summoned.
        $company = Company::current() ?? Filament::getTenant();

        if (! $company instanceof Company) {
            return collect();
        }

        $members = $company->users()->whereIn('users.id', $ids->all())->pluck('users.id')->map(fn ($id): int => (int) $id);

        return $ids->filter(fn (int $id): bool => $members->contains($id))->values();
    }

    /**
     * The users mentioned, loaded, members of this company only.
     *
     * @return Collection<int, User>
     */
    public static function usersIn(?string $text): Collection
    {
        $ids = self::in($text);

        return $ids->isEmpty() ? collect() : User::query()->whereKey($ids->all())->get();
    }

    /** What a picker inserts for a user: the long form, which Markdown renders as a link. */
    public static function token(User $user): string
    {
        return '@['.$user->name.'](user:'.$user->getKey().')';
    }
}
