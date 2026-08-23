<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Lead;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * Finding and merging duplicate leads.
 *
 * **Ships WITH the capture endpoint, not after it.** §13 originally called dedup "phase 3.5
 * at the latest", written when every lead was typed by a person who might notice the
 * duplicate. An open endpoint removes that person, so the two are one phase.
 *
 * Matching is on **email, phone or company name**. Deliberately any-of rather than all-of: a
 * web form gives an email and no phone, a trade-show card gives a phone and no email, and
 * requiring both would find nothing.
 *
 * **The older record's id survives.** Everything else points at it — activities, next
 * actions, opportunities, a converted contact — and keeping the newer id would mean
 * rewriting all of those to save nothing.
 */
class LeadDeduplication
{
    /**
     * Leads that look like this one, excluding itself.
     *
     * Ordered oldest first, because the oldest is the one a merge keeps.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Lead>
     */
    public function candidatesFor(Lead $lead)
    {
        $email = $this->normaliseEmail($lead->email);
        $phone = $this->normalisePhone($lead->phone);
        $company = $this->normaliseName($lead->company_name);

        // Nothing to match on is not a duplicate — it is a lead with a person's name and
        // nothing else, and matching those on name alone would merge two different Alis.
        if ($email === null && $phone === null && $company === null) {
            return Lead::query()->whereRaw('1 = 0')->get();
        }

        return Lead::query()
            ->when($lead->exists, fn ($query) => $query->whereKeyNot($lead->getKey()))
            ->where(function ($query) use ($email, $phone, $company): void {
                if ($email !== null) {
                    $query->orWhereRaw('lower(trim(email)) = ?', [$email]);
                }

                if ($phone !== null) {
                    // Compared on the last ten digits, so the country-code prefix stops
                    // mattering — a string comparison would call three spellings of one
                    // number three numbers.
                    $query->orWhereRaw(
                        'substr('.$this->digitsOnlyExpression('phone').', -10) = ?',
                        [$phone],
                    );
                }

                if ($company !== null) {
                    $query->orWhereRaw('lower(trim(company_name)) = ?', [$company]);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Merge a duplicate into the record it duplicates.
     *
     * **Keeps the older record and moves everything onto it**, then deletes the newer. The
     * failure mode here is losing history rather than losing the duplicate, which is why
     * activities and next actions are re-pointed rather than left to cascade away with the
     * row.
     *
     * Refuses to merge a converted lead: it is the origin record of a customer that exists,
     * and folding it into another lead would leave that customer pointing at a row that is
     * about somebody else.
     */
    public function merge(Lead $keep, Lead $discard): Lead
    {
        if ($keep->is($discard)) {
            throw new InvalidArgumentException('A lead cannot be merged into itself.');
        }

        if ($discard->isConverted()) {
            throw new InvalidArgumentException(
                'That lead has already become a customer, so it cannot be merged away — it is the origin '
                .'record for a contact that exists. Merge the other one into it instead.'
            );
        }

        // Always the older id, whatever the caller passed, so a merge cannot be run the
        // wrong way round by accident.
        if ($discard->getKey() < $keep->getKey()) {
            [$keep, $discard] = [$discard, $keep];
        }

        return TenantTransaction::run(function () use ($keep, $discard): Lead {
            $this->movePolymorphic($keep, $discard);

            // Deals move too: a deal against a duplicate lead is a real deal, and deleting
            // the lead would take it with them.
            \App\Modules\Crm\Models\Opportunity::query()
                ->where('lead_id', $discard->getKey())
                ->update(['lead_id' => $keep->getKey()]);

            // Fill blanks on the survivor from the duplicate. The older record wins where
            // both say something — it has been worked on longer — but a phone number the
            // newer row has and the older lacks is new information, and losing it is the
            // whole reason somebody notices a bad merge.
            $keep->fill(array_filter([
                'company_name' => $keep->company_name ?: $discard->company_name,
                'person_name' => $keep->person_name ?: $discard->person_name,
                'title' => $keep->title ?: $discard->title,
                'email' => $keep->email ?: $discard->email,
                'phone' => $keep->phone ?: $discard->phone,
                'whatsapp' => $keep->whatsapp ?: $discard->whatsapp,
                'city' => $keep->city ?: $discard->city,
                'lead_source_id' => $keep->lead_source_id ?: $discard->lead_source_id,
                'owner_employee_id' => $keep->owner_employee_id ?: $discard->owner_employee_id,
                'estimated_value' => $keep->estimated_value ?: $discard->estimated_value,
            ]))->save();

            // Notes are appended rather than chosen between: two people wrote them about
            // the same prospect, and both are worth keeping.
            if (trim((string) $discard->notes) !== '') {
                $keep->forceFill([
                    'notes' => trim(($keep->notes ? $keep->notes."\n\n" : '')
                        ."Merged from lead #{$discard->getKey()}:\n".$discard->notes),
                ])->save();
            }

            activity('Lead')
                ->performedOn($keep)
                ->causedBy(auth()->user())
                ->event('merged')
                ->withProperties(['merged_lead_id' => $discard->getKey()])
                ->log("Lead #{$discard->getKey()} merged into #{$keep->getKey()}");

            $discard->delete();

            return $keep->refresh();
        });
    }

    /**
     * Re-point activities and next actions at the surviving lead.
     *
     * Counted before and after by the test, because the failure mode is losing history: a
     * merge that dropped a call log would look successful and leave the survivor's timeline
     * short.
     */
    private function movePolymorphic(Lead $keep, Lead $discard): void
    {
        $alias = \App\Support\ModuleMap::alias(Lead::class);

        foreach ([\App\Modules\Crm\Models\Activity::class, \App\Modules\Crm\Models\NextAction::class] as $model) {
            $model::query()
                ->where('subject_type', $alias)
                ->where('subject_id', $discard->getKey())
                ->update(['subject_id' => $keep->getKey()]);
        }
    }

    /**
     * A driver-independent "digits only" comparison.
     *
     * SQLite has no REPLACE chain limit worth worrying about and MySQL has REGEXP_REPLACE,
     * but the portable form both accept is nested REPLACE — verbose, and correct on the
     * suite's SQLite as well as on production MySQL.
     */
    private function digitsOnlyExpression(string $column): string
    {
        $expression = $column;

        foreach ([' ', '-', '(', ')', '+', '.'] as $strip) {
            $expression = "replace({$expression}, '{$strip}', '')";
        }

        return $expression;
    }

    private function normaliseEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /**
     * The last ten digits, which is the number people actually share.
     *
     * `+92 300 1234567`, `0092-300-1234567` and `0300 1234567` are one number written three
     * ways, and a comparison on the full digit string calls them three. Ten digits is the
     * local subscriber number in this market, so taking the tail makes the country-code
     * prefix — present, doubled, or absent — stop mattering.
     */
    private function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        // Under seven digits is an extension or a typo, not a number worth matching on.
        if (strlen($digits) < 7) {
            return null;
        }

        return substr($digits, -10);
    }

    private function normaliseName(?string $name): ?string
    {
        $name = strtolower(trim((string) $name));

        return $name === '' ? null : $name;
    }
}
