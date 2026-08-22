<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Core\Models\Company;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Services\LeadDeduplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Phase 3.5: leads arriving without anybody typing them.
 *
 * §3 states the problem plainly — before this, the only way a lead got in was somebody
 * remembering to key it, so the register held the ones somebody remembered. This endpoint
 * invents nothing: it is the exact shape of the existing public status page.
 *
 * **Four constraints, each of which is a way this becomes a spam sink otherwise:**
 *
 *  1. **Two gates** (middleware): the token AND a per-company setting, so a leaked token can
 *     be closed without a deploy.
 *  2. **Rate limited per token, and rejected SILENTLY** — the same 202 as a success. An
 *     endpoint that answers 429 tells a bot how to pace itself.
 *  3. **A fixed field list**, mapped to `leads` columns. Not a JSON blob: a payload nobody
 *     validates becomes a column nobody can query.
 *  4. **It never converts.** It creates a lead, and a person qualifies it. §10's rule, at the
 *     one door where breaking it would be most tempting.
 *
 * Deduplication runs here rather than later, because this is precisely where the person who
 * would have noticed the duplicate has been removed.
 */
class LeadCaptureController
{
    /** The only fields accepted. Anything else in the payload is ignored, not stored. */
    private const FIELDS = ['company_name', 'person_name', 'title', 'email', 'phone', 'whatsapp', 'city', 'notes'];

    public function store(Request $request, LeadDeduplication $dedup): JsonResponse
    {
        $company = $request->attributes->get('leadCaptureCompany');

        // Re-made current: the middleware forgets the tenant in its `finally` so nothing
        // leaks out of a public request, which means the controller has to ask again.
        $company->makeCurrent();

        try {
            if ($this->isThrottled($request, $company)) {
                // Indistinguishable from a success. A bot cannot tell it has been
                // throttled, and an honest form that double-submits sees nothing odd.
                return $this->accepted();
            }

            $payload = $this->payload($request);

            // A lead needs a company or a person — the same invariant the model asserts.
            // Answered as accepted rather than 422: this is a public endpoint, and telling a
            // caller which field it needs is telling a bot how to pass validation.
            if ($payload === null) {
                return $this->accepted();
            }

            $this->capture($payload, $dedup);

            return $this->accepted();
        } catch (Throwable $e) {
            // Reported so it reaches the log, and NOT rethrown: an unauthenticated caller
            // must not learn the shape of a failure, and a broken integration should not be
            // able to tell a 500 from a success. Every outcome is the same 202.
            report($e);

            return $this->accepted();
        } finally {
            Company::forgetCurrent();
        }
    }

    /**
     * Create the lead, or fold it into the one it duplicates.
     *
     * An existing match is UPDATED with anything new rather than duplicated — a prospect who
     * fills the form twice with a phone number the second time has told us something, and a
     * second row would hide it.
     */
    private function capture(array $payload, LeadDeduplication $dedup): Lead
    {
        $lead = new Lead([
            ...$payload,
            'lead_source_id' => $this->webSource()?->getKey(),
            'status' => Lead::STATUS_NEW,
        ]);

        $existing = $dedup->candidatesFor($lead)->first();

        if ($existing) {
            // Only blanks. A person who has been worked on has notes and a rating that a
            // web form must not overwrite.
            $existing->fill(array_filter([
                'company_name' => $existing->company_name ?: $lead->company_name,
                'person_name' => $existing->person_name ?: $lead->person_name,
                'title' => $existing->title ?: $lead->title,
                'email' => $existing->email ?: $lead->email,
                'phone' => $existing->phone ?: $lead->phone,
                'whatsapp' => $existing->whatsapp ?: $lead->whatsapp,
                'city' => $existing->city ?: $lead->city,
            ]))->save();

            if (trim((string) $lead->notes) !== '') {
                $existing->forceFill([
                    'notes' => trim(($existing->notes ? $existing->notes."\n\n" : '')
                        .'From the web form on '.now()->format('d M Y H:i').":\n".$lead->notes),
                ])->save();
            }

            return $existing;
        }

        $lead->save();

        return $lead;
    }

    /**
     * The `Web form` source, looked up rather than created.
     *
     * LeadSourceSeeder ships the row precisely so an unauthenticated request never creates
     * reference data. Null is acceptable — a lead with no source is worse than no lead.
     */
    private function webSource(): ?LeadSource
    {
        return LeadSource::query()->where('name', 'Web form')->first();
    }

    /**
     * @return array<string, string>|null null when the payload names nobody
     */
    private function payload(Request $request): ?array
    {
        $payload = [];

        foreach (self::FIELDS as $field) {
            $value = $request->input($field);

            if (! is_scalar($value)) {
                continue;
            }

            // Truncated rather than rejected: a 10,000-character "city" is a bot, and
            // failing the whole submission over it would lose a real lead that had one bad
            // field.
            $trimmed = trim(mb_substr((string) $value, 0, $field === 'notes' ? 2000 : 255));

            if ($trimmed !== '') {
                $payload[$field] = $trimmed;
            }
        }

        $named = ($payload['company_name'] ?? '') !== '' || ($payload['person_name'] ?? '') !== '';

        return $named ? $payload : null;
    }

    /**
     * Per token per minute, and per IP within that.
     *
     * Keyed on the company rather than only the IP: a single misbehaving integration should
     * not be able to exhaust a limit shared with a legitimate one, and an attacker rotating
     * IPs should still meet a ceiling.
     */
    private function isThrottled(Request $request, Company $company): bool
    {
        $perCompany = 'lead-capture:'.$company->getKey();
        $perIp = $perCompany.':'.$request->ip();

        $companyLimit = (int) config('crm.lead_capture.per_minute', 30);
        $ipLimit = (int) config('crm.lead_capture.per_minute_per_ip', 5);

        if (RateLimiter::tooManyAttempts($perCompany, $companyLimit)
            || RateLimiter::tooManyAttempts($perIp, $ipLimit)) {
            return true;
        }

        RateLimiter::hit($perCompany);
        RateLimiter::hit($perIp);

        return false;
    }

    /**
     * The one response this endpoint ever gives.
     *
     * 202 for a success, a duplicate, a throttle, a nameless payload and an internal
     * failure alike. That is the point: the caller learns nothing about which happened, and
     * a bot gets no signal to tune against.
     */
    private function accepted(): JsonResponse
    {
        return response()->json(['status' => 'accepted'], 202);
    }
}
