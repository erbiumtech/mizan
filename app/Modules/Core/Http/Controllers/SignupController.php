<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Jobs\ProvisionCompany;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Signup;
use App\Modules\Core\Models\User;
use App\Notifications\SignupVerification;
use App\Support\CompanyProfiles;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The signup door — docs/saas-launch-plan.md §5.
 *
 * A third way a company comes to exist, beside the platform panel and the
 * console: an UNAUTHENTICATED visitor creating their FIRST company. Four rules,
 * each of which is a way this becomes a spam sink or a takeover otherwise:
 *
 *  1. **Nothing is provisioned until the mailbox is proven.** `store` creates a
 *     user and a signup row and sends one mail; `verify` is what dispatches the
 *     job. A bot that finds the form costs one email, not a database.
 *  2. **An existing email is refused, not attached.** The 2026-10-26 audit's
 *     critical finding was an invite attaching an existing account to a company
 *     with no consent. Here the person is told to sign in instead, and nothing
 *     else happens. A second company for an existing customer is a platform
 *     panel act.
 *  3. **Throttled submissions, honeypot hits and most failures look exactly like
 *     a success** — the same "check your inbox" page. An endpoint that answers
 *     429 tells a bot how to pace itself; LeadCaptureController's rule.
 *  4. **The panels never check `email_verified_at`.** Every user who exists today
 *     was created by a person and must not be locked out by this column.
 */
class SignupController extends Controller
{
    public function create(): Response
    {
        return response()->view('signup.form', [
            'profiles' => CompanyProfiles::optionsForType(Company::TYPE_BUSINESS),
        ]);
    }

    public function store(Request $request): Response|RedirectResponse
    {
        // Rule 3, before anything else is read: the honeypot (hidden from people
        // by the stylesheet, filled by bots) and the per-address limit both
        // answer with the success page, having done nothing.
        $key = 'signup:'.$request->ip();

        if (filled($request->input('website'))
            || RateLimiter::tooManyAttempts($key, (int) config('plans.signup.per_minute', 5))) {
            return $this->sent((string) $request->input('company', ''));
        }

        RateLimiter::hit($key);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', Password::min(8)],
            'company' => ['required', 'string', 'max:120'],
            'profile' => ['nullable', 'string', 'in:'.implode(',', array_keys(CompanyProfiles::optionsForType(Company::TYPE_BUSINESS)))],
        ]);

        $email = Str::lower(trim($data['email']));

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $pending = Signup::query()->where('user_id', $existing->getKey())->whereNull('company_id')->whereNull('failed_at')->latest()->first();

            // Their own earlier signup, mailbox not yet proven: the link went to
            // spam, or they closed the tab. Send it again; create nothing.
            if ($existing->email_verified_at === null && $pending !== null) {
                $existing->notify(new SignupVerification($pending->company_name));

                return $this->sent($pending->company_name);
            }

            // Rule 2. Said plainly rather than hidden behind the success page,
            // because the person on the other end is a customer, not a bot.
            return redirect()->route('signup')
                ->withInput($request->except('password'))
                ->withErrors([
                    'email' => 'There is already an account for this email address. Sign in instead; '
                        .'to add a company to your account, write to support.',
                ]);
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $email,
            'password' => Hash::make($data['password']),
            'status' => 1,
        ]);

        $signup = Signup::query()->create([
            'user_id' => $user->getKey(),
            'company_name' => $data['company'],
            'profile' => $data['profile'] ?? null,
        ]);

        $user->notify(new SignupVerification($signup->company_name));

        return $this->sent($signup->company_name);
    }

    /**
     * The signed link from the mail. `signed` middleware has already refused a
     * tampered or expired URL; the hash is Laravel's own pattern, so a link
     * for one address cannot verify another.
     */
    public function verify(Request $request, User $user, string $hash): RedirectResponse
    {
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if ($user->email_verified_at === null) {
            $user->markEmailAsVerified();
        }

        $signup = Signup::query()->where('user_id', $user->getKey())->latest()->first();

        // Dispatched once. A second click on the same link, or a mail client that
        // prefetches links, must not provision a second company.
        if ($signup !== null && $signup->isPending() && $signup->provisioning_started_at === null) {
            $signup->forceFill(['provisioning_started_at' => now()])->save();

            ProvisionCompany::dispatch($signup->getKey());
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('signup.ready');
    }

    /**
     * "Preparing your workspace." Polls itself with a meta refresh until the
     * job writes `company_id`, then sends the person into their company.
     */
    public function ready(Request $request): Response|RedirectResponse
    {
        $signup = Signup::query()->with('company')->where('user_id', $request->user()->getKey())->latest()->first();

        if ($signup === null) {
            return redirect(Filament::getPanel('admin')->getUrl());
        }

        if ($signup->company !== null) {
            return redirect(Filament::getPanel('admin')->getUrl($signup->company));
        }

        return response()->view('signup.ready', [
            'companyName' => $signup->company_name,
            'failed' => $signup->failed_at !== null,
        ]);
    }

    private function sent(string $companyName): Response
    {
        return response()->view('signup.sent', ['companyName' => $companyName]);
    }
}
