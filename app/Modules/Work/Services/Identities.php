<?php

namespace App\Modules\Work\Services;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\IntegrationConnection;
use App\Modules\Work\Models\IntegrationIdentity;

/**
 * Who a provider username is here — docs/project-management-plan.md §8.5.
 *
 * On first sight, matched by **verified** email against the company's users; failing that, the
 * identity is listed as unmatched and waits. A user may claim one, an administrator may assign
 * one. A smart commit from an unmatched identity is linked but not executed — the gap analysis
 * named identity matching as a missing row, and this is it.
 */
class Identities
{
    /**
     * The identity for an author, created on first sight.
     *
     * `verified` says whether the provider itself vouches for the email: GitLab's push payload
     * names the authenticated pusher's address and Clockify's user directory is the account's, so
     * both may match a person; a git commit's author email is whatever the committer typed, so a
     * GitHub or Bitbucket commit carries the address for display and matches nobody — otherwise a
     * push with a colleague's email in it would bind the pusher to that colleague and run smart
     * commits in their name.
     *
     * @param  array{username: ?string, id: ?string, email: ?string, verified?: bool}  $author
     */
    public function resolve(IntegrationConnection $connection, array $author): ?IntegrationIdentity
    {
        $username = $author['username'] ?? null;

        if (blank($username)) {
            return null;
        }

        $identity = IntegrationIdentity::query()->firstOrCreate(
            ['integration_connection_id' => $connection->getKey(), 'provider_username' => (string) $username],
            ['provider_user_id' => $author['id'] ?? null, 'email' => $author['email'] ?? null],
        );

        // A later delivery may carry the email the first did not.
        if ($identity->email === null && filled($author['email'] ?? null)) {
            $identity->forceFill(['email' => $author['email']])->save();
        }

        if (! $identity->isMatched() && ($author['verified'] ?? false) === true) {
            $this->matchByEmail($identity);
        }

        return $identity;
    }

    /**
     * An email the provider vouches for, against a member whose own address is verified: the only
     * automatic match — §8.5. Everything else waits to be claimed or assigned.
     */
    public function matchByEmail(IntegrationIdentity $identity): bool
    {
        if (blank($identity->email)) {
            return false;
        }

        $user = $this->members()
            ->where('users.email', $identity->email)
            ->whereNotNull('users.email_verified_at')
            ->first();

        if ($user === null) {
            return false;
        }

        $identity->forceFill(['user_id' => $user->getKey(), 'matched_how' => IntegrationIdentity::BY_EMAIL])->save();

        return true;
    }

    /** "That is me" — from the person's own side. */
    public function claim(IntegrationIdentity $identity, User $user): IntegrationIdentity
    {
        $identity->forceFill(['user_id' => $user->getKey(), 'matched_how' => IntegrationIdentity::BY_CLAIM])->save();

        return $identity;
    }

    /** An administrator says who it is. */
    public function assign(IntegrationIdentity $identity, ?User $user): IntegrationIdentity
    {
        $identity->forceFill([
            'user_id' => $user?->getKey(),
            'matched_how' => $user === null ? null : IntegrationIdentity::BY_MANUAL,
        ])->save();

        return $identity;
    }

    private function members()
    {
        $company = \App\Modules\Core\Models\Company::current();

        return $company === null ? User::query() : $company->users();
    }
}
