<?php

namespace App\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\RecurringInvoice;
use App\Modules\Invoicing\Services\InvoiceDeliveryService;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Notifications\RecordChanged;
use App\Notifications\SubscriptionNotice;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * The platform's own billing, through the operator's own books — docs/saas-launch-plan.md §6.
 *
 * The operator is a tenant. A customer's subscription is a `Contact` and a
 * `RecurringInvoice` in the operator's database; the month's invoice is raised by
 * the invoicing module exactly as any other agreement's; the money lands in the
 * operator's ledger with no second model of what a sale is. What this class adds
 * is the part no tenant can do for itself: reading every company from the
 * landlord, counting one company's employees, and writing the number onto
 * another company's agreement.
 *
 * Four operations, three of them run by the `billing:*` commands on the 1st and
 * daily. Each steps into a tenant with `within()`, which is `Company::execute()`
 * where a tenant connection exists and a plain call where it does not — the
 * single-database test suite, where every company's tables are the same tables.
 *
 * **The meter sets quantity; it never touches the price.** `unit_price` on the
 * agreement is what the customer was promised at subscription (§3.1). A list
 * price change in config/plans.php reaches an existing customer only when a
 * person edits their agreement after notice.
 */
class Subscriptions
{
    public const REASON_TRIAL_EXPIRED = 'trial_expired';

    public const REASON_OVERDUE = 'overdue';

    /** The operator's own company, or a refusal: the platform cannot bill through nobody's books. */
    public function operator(): Company
    {
        $slug = (string) config('saas.operator_company');

        if ($slug === '') {
            throw new RuntimeException('SAAS_OPERATOR_COMPANY is not set: the platform has no books to bill through.');
        }

        $operator = Company::query()->where('slug', $slug)->first();

        if ($operator === null) {
            throw new RuntimeException("SAAS_OPERATOR_COMPANY names [{$slug}], which is not a company.");
        }

        return $operator;
    }

    public function isConfigured(): bool
    {
        return filled(config('saas.operator_company'));
    }

    /**
     * Put a company on the books: a customer, an agreement in their currency,
     * one line per active employee — and the end of their trial.
     *
     * Currency is decided by the billing address and fixed here (§3.1): a
     * Pakistani address is billed in PKR, any other picks USD or EUR once.
     * Changing it later is a new agreement through support, because an
     * agreement's currency is its invoices' currency and the ledger has posted them.
     */
    public function subscribe(Company $company, string $email, string $address, string $country, ?string $currency): Company
    {
        if (! $company->hasPlan()) {
            throw new RuntimeException("{$company->name} is on no plan, so there is nothing to subscribe to.");
        }

        if ($company->billing_contact_id !== null) {
            throw new RuntimeException("{$company->name} is already subscribed.");
        }

        $currency = $this->currencyFor($country, $currency);
        $months = (int) (config("saas.cadence_months.{$currency}") ?? 1);
        $plan = $company->plan;
        $seats = $this->countSeats($company);
        $operator = $this->operator();

        $contactId = $this->within($operator, function () use ($company, $email, $address, $country, $currency, $months, $plan, $seats): int {
            $contact = Contact::query()->create([
                'name' => $company->name,
                'kind' => Contact::KIND_CUSTOMER,
                'email' => $email,
                'address_line_1' => $address,
                'address_line_2' => $country,
                'is_active' => true,
                'payment_terms_days' => (int) config('saas.due_days', 15),
            ]);

            $agreement = RecurringInvoice::query()->create([
                'contact_id' => $contact->getKey(),
                'kind' => Invoice::KIND_SALE,
                'description' => 'Mizan '.Plans::label($plan).' subscription — '.$company->name,
                'day_of_month' => 1,
                'due_days' => (int) config('saas.due_days', 15),
                // The first invoice is next month's, raised on its 1st, in advance.
                'starts_on' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'every_months' => $months,
                'currency_code' => $currency === Currency::baseCode() ? null : $currency,
                'is_active' => true,
                // The one place this is ever set: a job may issue what this raises.
                'auto_issue' => true,
            ]);

            $agreement->lines()->create([
                'description' => $this->lineDescription($plan, $seats, $months),
                'quantity' => $this->quantity($seats, $months),
                'unit_price' => Plans::price($plan, $currency),
                'account_id' => $this->revenueAccountId(),
                'tax_rate_id' => null,
                'sort' => 0,
            ]);

            return (int) $contact->getKey();
        });

        $company->forceFill([
            'billing_contact_id' => $contactId,
            'billing_currency' => $currency,
            'seats' => $seats,
            'seats_counted_at' => now(),
            'trial_ends_at' => null,
        ])->save();

        return $company;
    }

    /**
     * Count the company's active employees and write the number onto its
     * agreement — the quantity, and only the quantity.
     *
     * @return int the seats counted
     */
    public function meter(Company $company): int
    {
        if ($company->billing_contact_id === null) {
            throw new RuntimeException("{$company->name} is not subscribed.");
        }

        $seats = $this->countSeats($company);
        $plan = $company->plan;

        $this->within($this->operator(), function () use ($company, $seats, $plan): void {
            $agreement = $this->agreementFor($company);
            $line = $agreement->lines->first();

            if ($line === null) {
                throw new RuntimeException("The agreement for {$company->name} has no line to meter.");
            }

            $line->update([
                'quantity' => $this->quantity($seats, (int) $agreement->every_months),
                'description' => $this->lineDescription($plan, $seats, (int) $agreement->every_months),
            ]);
        });

        $company->forceFill(['seats' => $seats, 'seats_counted_at' => now()])->save();

        return $seats;
    }

    /**
     * Issue the period's subscription drafts and send them — the ones raised from
     * `auto_issue` agreements, and nobody else's. Three guards, each of which
     * holds the draft and tells the operator rather than sending a wrong invoice:
     * a seat count that jumped, a company a person suspended by hand, and a
     * draft whose issuing throws (reported, and the rest still issue).
     *
     * @return array{issued: array<int, string>, held: array<int, string>, failed: array<int, string>}
     */
    public function issue(?Carbon $period = null): array
    {
        $period = ($period ?? now())->copy()->startOfMonth();
        $result = ['issued' => [], 'held' => [], 'failed' => []];

        $this->within($this->operator(), function () use ($period, &$result): void {
            $drafts = Invoice::query()
                ->where('kind', Invoice::KIND_SALE)
                ->where('status', Invoice::STATUS_DRAFT)
                ->whereDate('period', $period->toDateString())
                ->whereHas('recurringInvoice', fn ($query) => $query->where('auto_issue', true))
                ->with(['recurringInvoice', 'contact', 'lines'])
                ->orderBy('id')
                ->get();

            foreach ($drafts as $draft) {
                $label = "{$draft->invoice_number} ({$draft->contact?->name})";
                $company = Company::query()->where('billing_contact_id', $draft->contact_id)->first();

                if ($company === null) {
                    $result['held'][] = $label;
                    $this->tellOperator('Subscription invoice held', "{$label}: no company carries this billing contact.");

                    continue;
                }

                // Guard 2. A person decided something about this customer; the draft
                // waits for them. An overdue suspension is the job's own and is invoiced.
                if ((int) $company->status !== 1 && $company->suspended_reason === null) {
                    $result['held'][] = $label;
                    $this->tellOperator('Subscription invoice held', "{$label}: {$company->name} was suspended by hand.");

                    continue;
                }

                // Guard 1. A count that jumped is held, not issued.
                $jump = $this->seatJump($draft);

                if ($jump !== null) {
                    $result['held'][] = $label;
                    $this->tellOperator(
                        'Subscription invoice held',
                        "{$label}: seats moved from {$jump['previous']} to {$jump['current']}. Issue it by hand if that is right.",
                    );

                    continue;
                }

                // Guard 3. One failure does not stop the rest.
                try {
                    $issued = app(InvoiceService::class)->issue($draft);
                    app(InvoiceDeliveryService::class)->send($issued);
                    $result['issued'][] = $label;
                } catch (Throwable $e) {
                    report($e);
                    $result['failed'][] = "{$label}: {$e->getMessage()}";
                    $this->tellOperator('Subscription invoice not issued', "{$label}: {$e->getMessage()}");
                }
            }
        });

        return $result;
    }

    /**
     * Suspend what has lapsed, and lift what has been put right — and never touch
     * a suspension a person imposed.
     *
     * @return array{suspended: array<int, string>, lifted: array<int, string>}
     */
    public function enforce(): array
    {
        $result = ['suspended' => [], 'lifted' => []];
        $grace = (int) config('saas.grace_days', 7);

        foreach (Company::query()->whereNotNull('plan')->orderBy('id')->get() as $company) {
            $trialLapsed = $company->billing_contact_id === null
                && $company->trial_ends_at !== null
                && $company->trial_ends_at->copy()->addDays($grace)->isPast();

            $overdue = $company->billing_contact_id !== null && $this->hasOverdueInvoice($company);

            if ((int) $company->status === 1) {
                if ($trialLapsed) {
                    $this->suspend($company, self::REASON_TRIAL_EXPIRED);
                    $result['suspended'][] = $company->slug;
                } elseif ($overdue) {
                    $this->suspend($company, self::REASON_OVERDUE);
                    $result['suspended'][] = $company->slug;
                }

                continue;
            }

            // Only a suspension this job imposed is this job's to lift.
            $lift = match ($company->suspended_reason) {
                self::REASON_TRIAL_EXPIRED => ! $trialLapsed,
                self::REASON_OVERDUE => ! $overdue,
                default => false,
            };

            if ($lift) {
                $company->forceFill(['status' => 1, 'suspended_reason' => null])->save();
                $result['lifted'][] = $company->slug;
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------ pieces

    /** The currency a billing address is billed in — the rule of §3.1, applied once. */
    public function currencyFor(string $country, ?string $currency): string
    {
        $home = (string) config('saas.home_currency', 'PKR');

        if (strtoupper($country) === strtoupper((string) config('saas.home_country', 'PK'))) {
            if ($currency !== null && strtoupper($currency) !== $home) {
                throw new RuntimeException("A Pakistani address is billed in {$home}.");
            }

            return $home;
        }

        $foreign = array_values(array_diff(Plans::currencies(), [$home]));

        if ($currency === null || ! in_array(strtoupper($currency), $foreign, true)) {
            throw new RuntimeException('Pick a currency: '.implode(' or ', $foreign).'.');
        }

        return strtoupper($currency);
    }

    public function countSeats(Company $company): int
    {
        return (int) $this->within($company, fn (): int => Employee::query()->where('is_active', true)->count());
    }

    /**
     * Run inside a company's tenant where there is one to switch to. In the
     * single-database test suite there is not, and `makeCurrent()` would throw;
     * the same idiom TenantFileAccess and the MPR provider use.
     */
    public function within(Company $company, Closure $callback): mixed
    {
        $switch = filled(config('multitenancy.tenant_database_connection_name'))
            && ! Company::current()?->is($company);

        return $switch ? $company->execute($callback) : $callback();
    }

    /** The one active sale agreement for a customer — refused rather than guessed at when there are none or two. */
    protected function agreementFor(Company $company): RecurringInvoice
    {
        $agreements = RecurringInvoice::query()
            ->active()
            ->where('kind', Invoice::KIND_SALE)
            ->where('contact_id', $company->billing_contact_id)
            ->with('lines')
            ->get();

        if ($agreements->count() !== 1) {
            throw new RuntimeException(
                "{$company->name} has {$agreements->count()} active agreements; the meter expects exactly one."
            );
        }

        return $agreements->first();
    }

    protected function quantity(int $seats, int $months): int
    {
        return max($seats, Plans::minimumSeats()) * max(1, $months);
    }

    protected function lineDescription(string $plan, int $seats, int $months): string
    {
        $billed = max($seats, Plans::minimumSeats());
        $floor = $billed > $seats ? " (minimum {$billed})" : '';

        return 'Mizan '.Plans::label($plan).', per active employee — '
            ."{$seats} active employee".($seats === 1 ? '' : 's').$floor
            ." × {$months} month".($months === 1 ? '' : 's')
            .', counted '.now()->toDateString();
    }

    protected function revenueAccountId(): int
    {
        $code = (string) config('saas.revenue_account_code', '4100');
        $id = Account::query()->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("The operator's chart has no account [{$code}] for subscription revenue.");
        }

        return (int) $id;
    }

    /**
     * Seats on this draft against the previous invoice from the same agreement,
     * when the move exceeds the hold ratio. Null means issue it.
     *
     * @return array{previous: int, current: int}|null
     */
    protected function seatJump(Invoice $draft): ?array
    {
        $ratio = (float) config('saas.issue_hold_ratio', 0.5);
        $months = max(1, (int) ($draft->recurringInvoice?->every_months ?? 1));

        $previous = Invoice::query()
            ->where('recurring_invoice_id', $draft->recurring_invoice_id)
            ->whereKeyNot($draft->getKey())
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID])
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->with('lines')
            ->first();

        if ($previous === null) {
            return null;
        }

        $before = (int) round((float) $previous->lines->first()?->quantity / $months);
        $now = (int) round((float) $draft->lines->first()?->quantity / $months);

        if ($before <= 0 || abs($now - $before) / $before <= $ratio) {
            return null;
        }

        return ['previous' => $before, 'current' => $now];
    }

    protected function hasOverdueInvoice(Company $company): bool
    {
        $cutoff = now()->subDays((int) config('saas.overdue_days', 30))->toDateString();

        return (bool) $this->within($this->operator(), fn (): bool => Invoice::query()
            ->where('kind', Invoice::KIND_SALE)
            ->where('contact_id', $company->billing_contact_id)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $cutoff)
            ->get()
            ->contains(fn (Invoice $invoice): bool => $invoice->outstanding() > 0.004));
    }

    protected function suspend(Company $company, string $reason): void
    {
        $company->forceFill(['status' => 0, 'suspended_reason' => $reason])->save();

        $emails = $this->customerEmails($company);

        if ($emails !== []) {
            Notification::route('mail', $emails)->notify(new SubscriptionNotice($company->name, $reason));
        }
    }

    /**
     * Who hears that a company was suspended: its billing contact once it has
     * one, otherwise its members — which for a lapsed trial is the person who
     * signed up.
     *
     * @return array<int, string>
     */
    protected function customerEmails(Company $company): array
    {
        if ($company->billing_contact_id !== null) {
            $email = $this->within($this->operator(), fn (): ?string => Contact::query()
                ->whereKey($company->billing_contact_id)
                ->first()
                ?->correspondenceEmail());

            if (filled($email)) {
                return [$email];
            }
        }

        return $company->users()->where('users.status', 1)->pluck('users.email')->filter()->values()->all();
    }

    /** The operator's own people are its super admins; the bell is where held invoices are read. */
    protected function tellOperator(string $title, string $body): void
    {
        foreach (User::query()->where('is_super_admin', true)->where('status', 1)->get() as $admin) {
            $admin->notify(new RecordChanged($title, $body));
        }
    }
}
