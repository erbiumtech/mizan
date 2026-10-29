<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Company;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\CompanyLetterhead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * The careers page — docs/saas-launch-plan.md §7.4.
 *
 * The lead-capture endpoint's rules, on a page people can read: whitelisted
 * fields only; a throttled, duplicated or failed submission answers exactly like
 * a success; and **it never advances a stage** — an application arrives at
 * "applied" and a person moves it, which is the CRM plan's rule at the door
 * where breaking it is most tempting.
 *
 * Pages are built as plain arrays and the tenant is re-made current for the
 * duration, because the middleware forgets it in its `finally`.
 */
class CareersController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->within($request, function (Company $company) use ($request): Response {
            return response()->view('careers.index', [
                'company' => $this->companyData($company),
                'token' => $request->route('token'),
                'vacancies' => Vacancy::query()
                    ->where('status', Vacancy::STATUS_OPEN)
                    ->orderByDesc('opened_on')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (Vacancy $vacancy): array => $this->vacancyData($vacancy))
                    ->all(),
            ]);
        });
    }

    public function show(Request $request, string $company, string $token, string $vacancy): Response
    {
        return $this->within($request, function (Company $current) use ($request, $vacancy): Response {
            $open = Vacancy::query()->where('status', Vacancy::STATUS_OPEN)->whereKey($vacancy)->first();

            abort_unless($open, 404);

            return response()->view('careers.show', [
                'company' => $this->companyData($current),
                'token' => $request->route('token'),
                'vacancy' => $this->vacancyData($open),
                'maxResumeKb' => (int) config('recruitment.careers.resume_max_kb', 8192),
            ]);
        });
    }

    public function store(Request $request, string $company, string $token, string $vacancy): Response|RedirectResponse
    {
        return $this->within($request, function (Company $current) use ($request, $vacancy): Response|RedirectResponse {
            $open = Vacancy::query()->where('status', Vacancy::STATUS_OPEN)->whereKey($vacancy)->first();

            abort_unless($open, 404);

            // Rule 3 before anything is read: the honeypot and the limit answer with the
            // success page, having done nothing.
            if (filled($request->input('website')) || $this->isThrottled($request, $current)) {
                return $this->sent($current, $open);
            }

            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email', 'max:190'],
                'phone' => ['nullable', 'string', 'max:40'],
                'cover' => ['nullable', 'string', 'max:2000'],
                'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:'.(int) config('recruitment.careers.resume_max_kb', 8192)],
            ]);

            try {
                $this->capture($open, $data, $request);
            } catch (Throwable $e) {
                // Reported, not rethrown: an unauthenticated caller must not learn the
                // shape of a failure. Every outcome is the same page.
                report($e);
            }

            return $this->sent($current, $open);
        });
    }

    /**
     * One applicant per email, one open application per vacancy.
     *
     * A second submission from the same address for the same vacancy is folded
     * into the first — a nervous candidate pressing twice is not two candidates
     * — and anything new they typed (a phone, a CV) is kept.
     *
     * @param  array<string, mixed>  $data
     */
    private function capture(Vacancy $vacancy, array $data, Request $request): void
    {
        $email = Str::lower(trim($data['email']));

        $applicant = Applicant::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'source' => 'careers'],
        );

        if (blank($applicant->phone) && filled($data['phone'] ?? null)) {
            $applicant->phone = $data['phone'];
        }

        if ($request->hasFile('resume') && blank($applicant->resume_path)) {
            // The tenant's own public disk, under the prefix the applicant-resume
            // access rule already guards.
            $applicant->resume_path = $request->file('resume')->store('applicant-resumes', 'public');
        }

        if (filled($data['cover'] ?? null)) {
            $applicant->notes = trim(($applicant->notes ? $applicant->notes."\n\n" : '').'Cover note ('.now()->toDateString().'): '.$data['cover']);
        }

        $applicant->save();

        $existing = Application::query()
            ->where('vacancy_id', $vacancy->getKey())
            ->where('applicant_id', $applicant->getKey())
            ->whereIn('stage', Application::OPEN_STAGES)
            ->exists();

        if (! $existing) {
            Application::query()->create([
                'vacancy_id' => $vacancy->getKey(),
                'applicant_id' => $applicant->getKey(),
                'stage' => Application::STAGE_APPLIED,
                'applied_on' => now()->toDateString(),
            ]);
        }
    }

    private function isThrottled(Request $request, Company $company): bool
    {
        $perCompany = 'careers:'.$company->getKey();
        $perIp = $perCompany.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($perCompany, (int) config('recruitment.careers.per_minute', 20))
            || RateLimiter::tooManyAttempts($perIp, (int) config('recruitment.careers.per_minute_per_ip', 5))) {
            return true;
        }

        RateLimiter::hit($perCompany);
        RateLimiter::hit($perIp);

        return false;
    }

    private function sent(Company $company, Vacancy $vacancy): Response
    {
        return response()->view('careers.sent', [
            'company' => $this->companyData($company),
            'vacancy' => $this->vacancyData($vacancy),
        ]);
    }

    /** @return array<string, mixed> */
    private function companyData(Company $company): array
    {
        $letterhead = CompanyLetterhead::data();

        return [
            'name' => $letterhead['legal_name'] ?: $company->name,
            'website' => $letterhead['website'] ?? null,
            'logo' => CompanyLetterhead::logoDataUri(),
        ];
    }

    /** @return array<string, mixed> */
    private function vacancyData(Vacancy $vacancy): array
    {
        return [
            'id' => $vacancy->getKey(),
            'title' => $vacancy->title,
            'designation' => $vacancy->designation,
            'department' => $vacancy->department,
            'employment_type' => $vacancy->employment_type,
            'openings' => $vacancy->openings,
            'description' => $vacancy->description,
            'salary' => $vacancy->salary_min || $vacancy->salary_max
                ? trim(($vacancy->currency_code ?: 'PKR').' '.($vacancy->salary_min ? number_format((float) $vacancy->salary_min) : '').($vacancy->salary_max ? ' – '.number_format((float) $vacancy->salary_max) : ''))
                : null,
            'opened_on' => $vacancy->opened_on?->toFormattedDateString(),
        ];
    }

    private function within(Request $request, \Closure $callback): mixed
    {
        $company = $request->attributes->get('careersCompany');
        $company->makeCurrent();

        try {
            return $callback($company);
        } finally {
            Company::forgetCurrent();
        }
    }
}
