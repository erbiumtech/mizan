<?php

namespace App\Modules\Invoicing\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\CustomerCredit;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Services\InvoiceService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One receipt, allocated across several invoices — `docs/erpnext-gap-plan.md` Phase 2, item 2.
 *
 * A customer sending one transfer for five invoices used to be five trips through the invoice screen, and
 * ERPNext answers that with a Payment Entry: a document with a references table, where whatever is left
 * over becomes an advance. This is the lazy form of the same thing, and the difference is the point — **a
 * screen, not a document type.** Every allocation goes through `InvoiceService::recordPayment()`, the
 * settlement path that already exists, so the FX treatment, the realised difference, the status transition
 * and the attribution to the invoice are all the tested ones.
 *
 * **What it will not do is take money it cannot place.** The allocations have to add up to the receipt,
 * because this application has nowhere to hold a balance that is not against an invoice — no advances
 * table, no on-account credit. The plan says to wait for somebody to have that problem rather than to
 * build the table on the strength of ERPNext having one, so an over- or under-allocated receipt is refused
 * with a sentence that says why.
 */
class RecordReceipt extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.invoicing.record-receipt';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing & Inventory';

    protected static ?string $title = 'Record a receipt';

    protected static ?int $navigationSort = 26;

    /**
     * Which customer paid. In the URL, so the screen can be linked to from their invoice.
     */
    #[Url]
    public int|string|null $contactId = null;

    public string $receivedOn = '';

    public string $amount = '';

    public string $reference = '';

    /** @var array<int|string, string> invoice id => the amount typed against it */
    public array $allocations = [];

    public function mount(): void
    {
        $this->receivedOn = now()->toDateString();
    }

    /**
     * Its own permission check as well as the module trait's.
     *
     * A page defining `canAccess()` silently shadows the trait's — the trap `docs/new-module-checklist.md`
     * §11 names — so the module gate is called explicitly beside the permission.
     */
    public static function canAccess(): bool
    {
        return static::moduleIsAvailable()
            && (auth()->user()?->can('PaymentCreate') ?? false);
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('record-receipt', 'Recording a receipt: Help')];
    }

    /** Customers with something outstanding, which is the only useful list to pick from. */
    public function customers(): array
    {
        return Contact::query()
            ->whereHas('invoices', fn ($invoices) => $invoices
                ->where('kind', Invoice::KIND_SALE)
                ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID]))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The chosen customer's open invoices, oldest first.
     *
     * Oldest first because that is the order money is applied in when nobody says otherwise, and because a
     * list that starts with the invoice most likely to be paid is the list somebody can tick down.
     *
     * @return \Illuminate\Support\Collection<int, Invoice>
     */
    public function openInvoices(): \Illuminate\Support\Collection
    {
        if (blank($this->contactId)) {
            return collect();
        }

        return Invoice::query()
            ->where('contact_id', $this->contactId)
            ->where('kind', Invoice::KIND_SALE)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();
    }

    /** What has been typed against the invoices so far. */
    public function allocated(): float
    {
        return round(array_sum(array_map(fn (mixed $amount): float => (float) $amount, $this->allocations)), 2);
    }

    /** What is left of the receipt, which is the figure that has to reach zero. */
    public function unallocated(): float
    {
        return round((float) $this->amount - $this->allocated(), 2);
    }

    /**
     * Spread the receipt down the list, oldest first.
     *
     * The ordinary case in one press: a customer pays what they owe and the money lands on the oldest
     * invoices until it runs out. Anything else is typed over the top, which is why this fills the boxes
     * rather than posting anything.
     */
    /** What would be held on account if the receipt were recorded now. */
    public function remainder(): float
    {
        return max(0.0, $this->unallocated());
    }

    public function allocateOldestFirst(): void
    {
        $remaining = round((float) $this->amount, 2);
        $this->allocations = [];

        foreach ($this->openInvoices() as $invoice) {
            if ($remaining < 0.01) {
                break;
            }

            $take = min($remaining, round((float) $invoice->outstanding(), 2));

            if ($take < 0.01) {
                continue;
            }

            $this->allocations[$invoice->getKey()] = (string) $take;
            $remaining = round($remaining - $take, 2);
        }

        if ($remaining >= 0.01) {
            Notification::make()
                ->warning()
                ->title('More than this customer owes')
                ->body(number_format($remaining, 2).' of the receipt is unallocated. Hold it on account for '
                    .'this customer, or reduce the amount.')
                ->send();
        }
    }

    /**
     * Record it, and hold whatever is not allocated as a credit for this customer.
     *
     * The same call with one flag — `docs/erpnext-gap-plan.md` §2.2. A deposit against no invoice at all is
     * this button with nothing allocated, which is why it does not require the table to have rows.
     */
    public function recordHoldingRemainder(): void
    {
        $this->record(holdOnAccount: true);
    }

    /** Settle the invoices, in one transaction, through the path that already posts settlements. */
    public function record(bool $holdOnAccount = false): void
    {
        $held = $holdOnAccount ? $this->remainder() : 0.0;

        try {
            // A deposit with nothing allocated still needs to know whose it is, and on this screen the
            // customer picker is the only place that says.
            if ($holdOnAccount && $this->allocations === [] && blank($this->contactId)) {
                throw new InvalidArgumentException('Choose the customer this money came from.');
            }

            $settled = $this->allocations === [] && $holdOnAccount
                ? $this->holdWholeReceipt()
                : app(InvoiceService::class)->recordBatchReceipt(
                    (float) $this->amount,
                    $this->receivedOn,
                    $this->allocations,
                    filled($this->reference) ? $this->reference : null,
                    $holdOnAccount,
                );
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($settled === []
                ? number_format($held, 2).' held on account'
                : count($settled).' '.(count($settled) === 1 ? 'invoice' : 'invoices').' settled'
                    .($held >= 0.01 ? ', '.number_format($held, 2).' held on account' : ''))
            ->send();

        $this->allocations = [];
        $this->amount = '';
        $this->reference = '';
    }

    /** @return array<int, \App\Modules\Invoicing\Models\Invoice> nothing settled; the whole receipt is a deposit */
    private function holdWholeReceipt(): array
    {
        app(InvoiceService::class)->holdOnAccount(
            Contact::query()->findOrFail($this->contactId),
            (float) $this->amount,
            $this->receivedOn,
            filled($this->reference) ? $this->reference : null,
        );

        return [];
    }

    /** What this customer is already holding, so the screen can say so before more is added. */
    public function creditsOnAccount(): float
    {
        if (blank($this->contactId)) {
            return 0.0;
        }

        return round((float) CustomerCredit::query()
            ->where('contact_id', $this->contactId)
            ->available()
            ->get()
            ->sum(fn (CustomerCredit $credit): float => $credit->remaining()), 2);
    }
}
