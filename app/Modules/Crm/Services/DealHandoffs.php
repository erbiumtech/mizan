<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Opportunity;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 6: what a won deal can become, and renewals.
 *
 * **Three optional, guarded follow-ons, each one action a human takes.** None automatic —
 * docs/crms-plan.md §3 and §10 both insist, and the reason is stated once and applies to all
 * three: *a won deal is a sales fact; an invoice is a legal document.* Since FBR digital
 * invoicing that is stronger than a preference — a transmitted invoice cannot be freely
 * voided after 72 hours, so an invoice conjured by winning a deal is something nobody can
 * take back.
 *
 * **Renewals own no table.** §3 settles this: a renewal is a *view over* `recurring_invoices`,
 * which already carry the schedule and the next issue date. A parallel table would be a
 * second answer to "when does this renew", and the invoice would win. What CRM adds is what
 * Invoicing has no opinion about — an owner, a next action, and the fact that a renewal is at
 * risk.
 *
 * `beneficiary_subscriptions` is **not** this. That table is what *we* pay for. §13 asked for
 * the name to be made explicit precisely because two things called subscriptions in one
 * codebase will be confused at least once.
 */
class DealHandoffs
{
    public function canOpenProject(): bool
    {
        return modules()->enabled('projects');
    }

    public function canRaiseInvoice(): bool
    {
        return modules()->enabled('invoicing');
    }

    /** Renewals need Invoicing: without it there are no recurring invoices to be a view of. */
    public function renewalsAvailable(): bool
    {
        return modules()->enabled('invoicing');
    }

    /**
     * Open a project for a won deal, and record the link.
     *
     * A deliberate act, and only on a won deal: opening a project for something that might
     * not happen puts phantom work in front of a delivery team.
     */
    public function openProject(Opportunity $opportunity, array $overrides = []): Project
    {
        if (! $this->canOpenProject()) {
            throw new RuntimeException(
                'Opening a project needs the Projects module: there is nothing for the deal to become.'
            );
        }

        $this->assertWon($opportunity, 'a project');

        if ($opportunity->project_id) {
            throw new InvalidArgumentException('This deal already has a project.');
        }

        $project = Project::create(array_merge([
            'code' => $this->projectCode($opportunity),
            'name' => $opportunity->title,
            'status' => Project::STATUS_PLANNED,
            // Whoever won it is the natural first point of contact for the engagement.
            'manager_employee_id' => $opportunity->owner_employee_id,
            // Carried so the project's own invoices and the deal agree about the client.
            'contact_id' => $opportunity->contact_id,
            'start_date' => now()->toDateString(),
        ], $overrides));

        $opportunity->forceFill(['project_id' => $project->getKey()])->save();

        return $project;
    }

    /**
     * Raise a **draft** invoice from a won deal.
     *
     * Draft, and stopping there. Issuing is what transmits to FBR, and transmission is what
     * cannot be undone — so it stays the explicit act it already is in Invoicing.
     *
     * One line, described from the deal. A deal knows what it is worth, not how it breaks
     * down; a quote is where line detail lives, and `QuotationService::convertToInvoice()` is
     * the richer path when there is one.
     */
    public function raiseDraftInvoice(Opportunity $opportunity): Invoice
    {
        if (! $this->canRaiseInvoice()) {
            throw new RuntimeException('Raising an invoice needs the Invoicing module.');
        }

        $this->assertWon($opportunity, 'an invoice');

        if ($opportunity->invoice_id) {
            throw new InvalidArgumentException(
                'This deal has already been invoiced. Raising another would bill the customer twice.'
            );
        }

        if (! $opportunity->contact_id) {
            throw new InvalidArgumentException(
                'This deal is against a lead rather than a customer. Convert the lead first — an invoice '
                .'needs a party the ledger can bill.'
            );
        }

        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'status' => Invoice::STATUS_DRAFT,
            'contact_id' => $opportunity->contact_id,
            'currency_code' => $opportunity->currency_code,
            'exchange_rate' => $opportunity->exchange_rate,
            'invoice_date' => now()->toDateString(),
            'project_id' => $opportunity->project_id,
            'subtotal' => $opportunity->amount,
            'tax_amount' => 0,
            'total' => $opportunity->amount,
            'memo' => "From deal: {$opportunity->title}",
        ]);

        $invoice->lines()->create([
            'description' => $opportunity->title,
            'quantity' => 1,
            'unit_price' => $opportunity->amount,
            'line_total' => $opportunity->amount,
        ]);

        $opportunity->forceFill(['invoice_id' => $invoice->getKey()])->save();

        activity('Opportunity')
            ->performedOn($opportunity)
            ->causedBy(auth()->user())
            ->event('invoiced')
            ->withProperties(['invoice_id' => $invoice->getKey()])
            ->log("Draft invoice raised from deal: {$opportunity->title}");

        return $invoice->refresh();
    }

    /**
     * Renewals coming up — a **view** over recurring invoices, owning nothing.
     *
     * Surfaced next to the pipeline because renewing is a sales activity and losing one is a
     * lost deal. What CRM adds is the owner and whether the renewal is at risk; everything
     * about the schedule stays Invoicing's.
     *
     * **Asked period by period rather than by reading a `next_issue_date` column, because
     * there is no such column.** A recurring invoice carries `day_of_month`, `starts_on` and
     * `ends_on`, and answers `coversPeriod()` / `invoiceDateFor()` for a month. Inventing a
     * denormalised next-date here would be a second answer to "when does this renew" — the
     * exact thing §3 refuses by giving renewals no table of their own.
     *
     * @param  int  $months  how many months ahead to look
     * @return Collection<int, array{recurring: RecurringInvoice, due_on: Carbon, period: Carbon, party: ?string, at_risk: bool}>
     */
    public function renewalsDue(int $months = 2, ?string $from = null): Collection
    {
        if (! $this->renewalsAvailable()) {
            return new Collection;
        }

        $start = Carbon::parse($from ?: now())->startOfMonth();
        $renewals = new Collection;

        foreach (RecurringInvoice::query()->active()->with('contact')->get() as $recurring) {
            for ($offset = 0; $offset < max(1, $months); $offset++) {
                $period = $start->copy()->addMonths($offset);

                if (! $recurring->coversPeriod($period)) {
                    continue;
                }

                $renewals->push([
                    'recurring' => $recurring,
                    'period' => $period,
                    'due_on' => $recurring->invoiceDateFor($period),
                    'party' => $recurring->contact?->name,
                    // The CRM's own judgement, and the only thing it adds: a renewal with
                    // nobody working it. A renewal that is LOST is recorded as a lost
                    // opportunity, so churn appears in win/loss beside new business rather
                    // than in a report of its own.
                    'at_risk' => ! $this->renewalHasOwner($recurring),
                ]);

                // One row per renewal, not one per period it will ever cover: the question is
                // "what needs attention", and the next occurrence is the answer.
                break;
            }
        }

        return $renewals->sortBy('due_on')->values();
    }

    /**
     * Whether anybody is working this renewal.
     *
     * An open deal against the same customer counts. Deliberately loose: the question is
     * "has anybody got their eye on this", and requiring a formal link between a recurring
     * invoice and a deal would mean a second table — which §3 refuses.
     */
    private function renewalHasOwner(RecurringInvoice $recurring): bool
    {
        if (! $recurring->contact_id || ! modules()->enabled('crm')) {
            return false;
        }

        return Opportunity::query()
            ->open()
            ->where('contact_id', $recurring->contact_id)
            ->exists();
    }

    private function assertWon(Opportunity $opportunity, string $what): void
    {
        if (! $opportunity->isWon()) {
            throw new InvalidArgumentException(
                "Only a won deal becomes {$what}. This one is "
                .($opportunity->isOpen() ? 'still open' : $opportunity->outcome).'.'
            );
        }
    }

    /**
     * A project code from the deal.
     *
     * Suffixed until it is free rather than assumed unique: `projects.code` is unique, and a
     * collision on a hand-off would fail the whole action for a reason nobody would guess.
     */
    private function projectCode(Opportunity $opportunity): string
    {
        $base = 'D-'.str_pad((string) $opportunity->getKey(), 4, '0', STR_PAD_LEFT);
        $code = $base;
        $suffix = 1;

        while (Project::where('code', $code)->exists()) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }
}
