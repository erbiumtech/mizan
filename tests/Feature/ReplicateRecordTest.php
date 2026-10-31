<?php

namespace Tests\Feature;

use App\Filament\Actions\ReplicateRecordAction;
use App\Modules\Accounting\Filament\Resources\Accounts\Pages\EditAccount;
use App\Modules\Accounting\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\CustomField;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Modules\Invoicing\Filament\Resources\TaxRates\Pages\ListTaxRates;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\InvoiceLine;
use App\Modules\Invoicing\Models\TaxRate;
use App\Modules\Invoicing\Services\InvoiceService;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Filament\Resources\WorkItems\Pages\EditWorkItem;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\WorkItems;
use App\Support\Replication\RecordReplicator;
use Database\Seeders\WorkWorkflowSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use RuntimeException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Replicate — docs/replicate-records.md.
 *
 * What is worth proving: who sees the button, that a document copies to a *new draft* rather than
 * a second copy of what was issued and paid, that a work item takes the next key and the start of
 * its workflow, that a unique code is asked for and checked, and that a copy which fails halfway
 * leaves nothing behind — not a header without lines, and not a log line pointing at nothing.
 */
class ReplicateRecordTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'replicate@test.local'));
        $this->setCurrentTenant();
    }

    public function test_the_action_is_offered_to_someone_who_may_create_the_record(): void
    {
        $account = Account::where('code', '5000')->firstOrFail();

        Livewire::test(ListAccounts::class)
            ->assertActionVisible(TestAction::make('replicateRecord')->table($account));

        Livewire::test(EditAccount::class, ['record' => $account->getRouteKey()])
            ->assertActionVisible('replicateRecord');
    }

    public function test_the_action_is_hidden_from_someone_who_may_not_create_the_record(): void
    {
        $account = Account::where('code', '5000')->firstOrFail();

        $this->actingAs($employee = $this->makeUser('Employee', 'no-create@test.local'));
        $this->assertFalse($employee->can('create', Account::class), 'the fixture must lack the create permission');

        $this->assertFalse(ReplicateRecordAction::allowsReplicating($account));
    }

    public function test_an_invoice_copies_to_a_new_draft_with_its_lines_and_none_of_its_payments(): void
    {
        $invoice = $this->issuedInvoiceWithLines();
        $invoice->forceFill(['amount_paid' => 40_000, 'status' => Invoice::STATUS_PARTIALLY_PAID])->save();

        Livewire::test(ListInvoices::class)
            ->callAction(TestAction::make('replicateRecord')->table($invoice))
            ->assertHasNoActionErrors();

        $copy = Invoice::query()->whereKeyNot($invoice->getKey())->latest('id')->firstOrFail();

        $this->assertNotSame($invoice->invoice_number, $copy->invoice_number, 'a new number');
        $this->assertStringStartsWith('INV-', $copy->invoice_number);
        $this->assertSame(Invoice::STATUS_DRAFT, $copy->status);
        $this->assertEquals(0, (float) $copy->amount_paid, 'nothing paid on the copy');
        $this->assertNull($copy->journal_entry_id, 'and nothing posted');
        $this->assertSame(today()->toDateString(), $copy->invoice_date->toDateString());
        $this->assertSame(today()->addDays(30)->toDateString(), $copy->due_date->toDateString(), 'the same thirty days to pay');
        $this->assertSame((int) $invoice->contact_id, (int) $copy->contact_id);

        $this->assertSame(
            $invoice->lines()->orderBy('id')->pluck('description')->all(),
            $copy->lines()->orderBy('id')->pluck('description')->all(),
        );
        $this->assertSame(2, $invoice->lines()->count(), 'the original keeps its own lines');

        // The original is exactly as it was.
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->fresh()->status);
    }

    public function test_the_invoice_view_offers_replicate_in_its_overflow(): void
    {
        $invoice = $this->issuedInvoiceWithLines();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionVisible('replicateRecord');
    }

    public function test_a_credit_note_cannot_be_replicated(): void
    {
        $note = new Invoice(['kind' => Invoice::KIND_CREDIT_NOTE]);

        $this->assertFalse($note->canBeReplicated());
        $this->assertFalse(ReplicateRecordAction::allowsReplicating($note));
    }

    public function test_a_work_item_gets_the_next_key_and_the_first_status(): void
    {
        $this->seed(WorkWorkflowSeeder::class);

        $project = Project::create([
            'code' => 'PRJ-WEB-01',
            'name' => 'The website',
            'status' => 'active',
            'work_workflow_id' => WorkWorkflow::default()->getKey(),
        ]);

        $item = app(WorkItems::class)->create($project, ['title' => 'Export fails on Safari', 'priority' => 'high'], auth()->user());
        $item = $item->moveTo(WorkStatus::query()->where('name', 'In progress')->firstOrFail());

        Livewire::test(EditWorkItem::class, ['record' => $item->getRouteKey()])
            ->callAction('replicateRecord')
            ->assertHasNoActionErrors();

        $copy = WorkItem::query()->whereKeyNot($item->getKey())->with('status')->latest('id')->firstOrFail();

        $this->assertSame('WEB-2', $copy->key);
        $this->assertSame(2, $copy->number);
        $this->assertSame(WorkStatus::TODO, $copy->status->category, 'back to the start of the workflow');
        $this->assertSame('Export fails on Safari', $copy->title);
        $this->assertSame('high', $copy->priority);
        $this->assertNull($copy->started_at);
        $this->assertSame('In progress', $item->fresh()->status->name, 'the original did not move');
    }

    public function test_an_account_copy_asks_for_a_code_and_refuses_one_in_use(): void
    {
        $account = Account::where('code', '5000')->firstOrFail();

        Livewire::test(ListAccounts::class)
            ->mountAction(TestAction::make('replicateRecord')->table($account))
            ->assertActionDataSet(['code' => '5000-COPY']);

        Livewire::test(ListAccounts::class)
            ->callAction(TestAction::make('replicateRecord')->table($account), ['code' => '1000'])
            ->assertHasActionErrors(['code']);

        $this->assertSame(1, Account::where('name', $account->name)->count(), 'nothing was copied');

        Livewire::test(ListAccounts::class)
            ->callAction(TestAction::make('replicateRecord')->table($account), ['code' => '5999'])
            ->assertHasNoActionErrors();

        $copy = Account::where('code', '5999')->firstOrFail();
        $this->assertSame($account->name, $copy->name);
        $this->assertSame($account->type, $copy->type);
        $this->assertEquals(0, (float) $copy->balance);
    }

    public function test_master_data_with_nothing_unique_copies_on_confirmation(): void
    {
        $rate = TaxRate::create(['name' => 'GST 18%', 'rate' => 18, 'is_default' => true]);

        Livewire::test(ListTaxRates::class)
            ->callAction(TestAction::make('replicateRecord')->table($rate))
            ->assertHasNoActionErrors();

        $copy = TaxRate::query()->whereKeyNot($rate->getKey())->where('name', 'GST 18%')->firstOrFail();

        $this->assertEquals(18, (float) $copy->rate);
        $this->assertFalse((bool) $copy->is_default, 'only one default, and it is still the original');
        $this->assertTrue((bool) $rate->fresh()->is_default);
    }

    public function test_a_failure_copying_a_child_rolls_the_whole_copy_back(): void
    {
        $invoice = $this->issuedInvoiceWithLines();
        $before = Invoice::count();

        InvoiceLine::creating(function (): void {
            throw new RuntimeException('The line could not be copied.');
        });

        try {
            app(RecordReplicator::class)->replicate($invoice);
            $this->fail('The copy should have failed on its lines.');
        } catch (RuntimeException $e) {
            $this->assertSame('The line could not be copied.', $e->getMessage());
        }

        $this->assertSame($before, Invoice::count(), 'no header without its lines');
        $this->assertFalse(ActivityLog::query()->where('event', 'replicated')->exists(), 'and no log line pointing at nothing');
    }

    public function test_the_copy_is_logged_against_the_original_in_the_current_company(): void
    {
        $invoice = $this->issuedInvoiceWithLines();

        $copy = app(RecordReplicator::class)->replicate($invoice);

        $entry = ActivityLog::query()
            ->where('event', 'replicated')
            ->where('subject_type', $copy->getMorphClass())
            ->where('subject_id', $copy->getKey())
            ->firstOrFail();

        $this->assertSame('Replicated from '.$invoice->invoice_number, $entry->description);
        $this->assertSame((int) $invoice->getKey(), (int) $entry->properties['replicated_from']['id']);
        $this->assertSame((int) $this->tenant->getKey(), (int) $entry->company_id, 'logged in the company the copy was made in');
        $this->assertSame((int) auth()->id(), (int) $entry->causer_id);

        // The copy itself lives on the original's connection — the current company's database.
        $this->assertSame($invoice->getConnectionName(), $copy->getConnectionName());
        $this->assertTrue(Invoice::query()->whereKey($copy->getKey())->exists());
    }

    public function test_custom_field_values_come_with_the_copy(): void
    {
        CustomField::create([
            'model_type' => Contact::class, 'code' => 'region', 'name' => 'Region', 'type' => 'text',
        ]);

        $contact = Contact::create(['name' => 'Globex', 'kind' => 'customer', 'is_active' => true]);
        $contact->saveCustomFields(['region' => 'North']);

        $copy = app(RecordReplicator::class)->replicate($contact);

        $this->assertSame('North', $copy->fresh()->customFieldsData()['region']);
    }

    private function issuedInvoiceWithLines(): Invoice
    {
        $customer = Contact::create(['name' => 'Karachi Textiles', 'kind' => Contact::KIND_CUSTOMER]);

        $invoice = Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $customer->getKey(),
            'invoice_date' => '2026-10-01',
            'due_date' => '2026-10-31',
        ]);

        foreach (['Site supervision, October' => 60_000, 'Concrete testing' => 40_000] as $description => $amount) {
            $invoice->lines()->create([
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
            ]);
        }

        $invoice->forceFill(['subtotal' => 100_000, 'tax_amount' => 0, 'total' => 100_000])->save();

        app(InvoiceService::class)->issue($invoice);

        return $invoice->refresh();
    }
}
