<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Lead;
use App\Modules\Invoicing\Models\Contact;
use App\Support\TenantTransaction;
use InvalidArgumentException;
use RuntimeException;

/**
 * Turning a prospect into somebody you can invoice.
 *
 * This class is the whole of the CRM → Invoicing boundary, and it is deliberately the
 * only place that crosses it. `crm` declares no requirement on `invoicing`
 * (docs/crms-plan.md §1), so everything here is guarded: `isAvailable()` is what every
 * surface asks before offering the action, and `convert()` refuses rather than assumes.
 *
 * What it does NOT do is as important as what it does:
 *
 *  - **It does not raise an invoice.** A converted lead is a sales fact; an invoice is
 *    a legal document, and §10 is explicit that a human raises it. This is doubly true
 *    now that FBR digital invoicing means a transmitted invoice cannot be freely voided
 *    after 72 hours — see docs/fbr-digital-invoicing-plan.md.
 *  - **It does not post anything to the ledger.** Nothing about winning work is a
 *    journal entry.
 *  - **It does not delete or hide the lead.** The row stays, marked converted and
 *    pointing at the contact it became, because "where did this customer come from" is
 *    a lead-source question asked years later.
 */
class LeadConversion
{
    /**
     * Whether conversion can be offered at all.
     *
     * The guard every caller uses, rather than each one remembering the module name.
     */
    public function isAvailable(): bool
    {
        return modules()->enabled('invoicing');
    }

    /**
     * Create the Contact this lead becomes, and record the link both ways.
     *
     * Idempotent by refusal rather than by silence: converting twice would create a
     * second customer for one prospect, and the second is the one nobody notices until
     * an invoice goes to the wrong record. The caller gets the existing contact.
     */
    public function convert(Lead $lead, array $overrides = []): Contact
    {
        if (! $this->isAvailable()) {
            // Not a policy failure and not a validation message: the module is not
            // licensed, and no wording on a form will change that. Loud, because the
            // only way to reach this is a caller that skipped isAvailable().
            throw new RuntimeException(
                'A lead cannot be converted without the Invoicing module: there is nothing for it to become.'
            );
        }

        if ($lead->isConverted()) {
            throw new InvalidArgumentException(
                'This lead has already been converted. Converting again would create a second customer for one prospect.'
            );
        }

        if ($lead->status === Lead::STATUS_LOST) {
            throw new InvalidArgumentException(
                'This lead was marked lost. Reopen it first, so the record says the deal came back.'
            );
        }

        return TenantTransaction::run(function () use ($lead, $overrides): Contact {
            $contact = Contact::create(array_merge([
                // The company is the party the ledger bills; the person is a
                // contact_person, which phase 2 adds. A lead with only a person
                // named falls back to their name, because a sole trader is both.
                'name' => trim((string) $lead->company_name) !== ''
                    ? $lead->company_name
                    : $lead->person_name,
                'kind' => Contact::KIND_CUSTOMER,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'is_active' => true,
                // Deliberately not defaulted to a number of days. Contact::TERMS
                // leaves null as its own option because "none agreed" and "due on
                // receipt" are different facts, and inventing terms here would put a
                // brand-new customer into the overdue bucket on day one.
                'payment_terms_days' => null,
            ], $overrides));

            $lead->forceFill([
                'status' => Lead::STATUS_CONVERTED,
                'converted_contact_id' => $contact->getKey(),
                'converted_at' => now(),
                // A lead cannot be both converted and lost. Clearing these is what
                // keeps win/loss honest when somebody marks a lead lost and it later
                // comes back.
                'lost_reason' => null,
                'lost_at' => null,
            ])->save();

            activity('Lead')
                ->performedOn($lead)
                ->causedBy(auth()->user())
                ->event('converted')
                ->withProperties([
                    'contact_id' => $contact->getKey(),
                    'contact_name' => $contact->name,
                ])
                ->log("Lead converted to customer {$contact->name}");

            return $contact;
        });
    }

    /**
     * Mark a lead lost, with a reason.
     *
     * The reason is required for the same purpose the refusal reason on leave is:
     * win/loss by reason is the report §8 says is worth more than the forecast, and a
     * blank reason contributes nothing to it. Free text at this phase — `lost_reasons`
     * becomes a table in phase 4, when there is a report to read it.
     */
    public function markLost(Lead $lead, string $reason): Lead
    {
        if ($lead->isConverted()) {
            throw new InvalidArgumentException(
                'This lead has already been converted, so it cannot be marked lost. Losing the customer later is a lost deal, not a lost lead.'
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A lost lead needs a reason — without one it counts for nothing in win/loss.');
        }

        $lead->forceFill([
            'status' => Lead::STATUS_LOST,
            'lost_reason' => trim($reason),
            'lost_at' => now(),
        ])->save();

        return $lead;
    }

    /** Put a lost lead back in play; deals do come back. */
    public function reopen(Lead $lead): Lead
    {
        if ($lead->isConverted()) {
            throw new InvalidArgumentException('A converted lead is already a customer.');
        }

        $lead->forceFill([
            'status' => Lead::STATUS_WORKING,
            'lost_reason' => null,
            'lost_at' => null,
        ])->save();

        return $lead;
    }
}
