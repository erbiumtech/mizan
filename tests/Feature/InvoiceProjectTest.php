<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Projects\Models\Project;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An invoice can name the engagement it belongs to — GnuCash calls this a "job",
 * and it was the one row of the comparison left unbuilt.
 *
 * The question it answers is not "what does this client owe" — ageing already
 * did that — but "what has this piece of work been billed", for a client with
 * four of them running at once.
 *
 * The half that needs guarding is licensing. Invoicing must stay sellable to a
 * company that runs no projects, so the field is offered only where Projects is
 * on. The column exists in every tenant either way, because licensing decides
 * what is offered rather than what is migrated.
 */
class InvoiceProjectTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Contact $client;

    protected function setUp(): void
    {
        parent::setUp();

        // AccountingTestCase does not seed currencies, and the invoice form
        // validates currency_code against the active list — nothing to do with
        // projects, but the form will not save without it.
        $this->seed(\Database\Seeders\CurrencySeeder::class);

        $this->actingAs($this->makeUser('Administrator', 'jobs@test.local'));
        $this->setCurrentTenant();

        $this->client = Contact::create([
            'name' => 'Erbium AG',
            'kind' => Contact::KIND_BOTH,
            'is_active' => true,
        ]);
    }

    private function project(string $code, string $name): Project
    {
        return Project::create(['code' => $code, 'name' => $name]);
    }

    private function invoice(?Project $project, float $total = 1000): Invoice
    {
        return Invoice::create([
            'kind' => Invoice::KIND_SALE,
            'contact_id' => $this->client->getKey(),
            'project_id' => $project?->getKey(),
            'invoice_date' => '2026-07-10',
            'subtotal' => $total, 'tax_amount' => 0, 'total' => $total,
        ]);
    }

    private function unlicenseProjects(): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => Filament::getTenant()->getKey(), 'module' => 'projects'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();
    }

    public function test_an_invoice_can_belong_to_a_project(): void
    {
        $project = $this->project('APOLLO', 'Apollo rebuild');

        $this->assertSame('Apollo rebuild', $this->invoice($project)->project->name);
    }

    public function test_an_invoice_without_one_is_still_perfectly_valid(): void
    {
        // The field is optional and always was: most invoices are not against an
        // engagement, and requiring one would break every existing flow.
        $this->assertNull($this->invoice(null)->project);
    }

    public function test_what_one_engagement_has_been_billed(): void
    {
        // The question the feature exists for. A client with two pieces of work
        // running has one ageing figure and two answers to "how much was this
        // worth".
        $apollo = $this->project('APOLLO', 'Apollo rebuild');
        $mercury = $this->project('MERCURY', 'Mercury support');

        $this->invoice($apollo, 5000);
        $this->invoice($apollo, 2500);
        $this->invoice($mercury, 900);
        $this->invoice(null, 400);

        $this->assertEqualsWithDelta(
            7500,
            (float) Invoice::where('project_id', $apollo->getKey())->sum('total'),
            0.01,
        );
        $this->assertEqualsWithDelta(
            8800,
            (float) Invoice::sum('total'),
            0.01,
            'The client total is unchanged — the project is a lens, not a filter on the books.',
        );
    }

    public function test_deleting_a_project_leaves_its_invoices_alone(): void
    {
        // nullOnDelete, not cascade. An invoice is a document that was sent to a
        // customer; the engagement it was filed under going away must not take
        // the money with it.
        $project = $this->project('APOLLO', 'Apollo rebuild');
        $invoice = $this->invoice($project, 5000);

        $project->delete();

        $this->assertNotNull($invoice->fresh(), 'The invoice was deleted with its project.');
        $this->assertNull($invoice->fresh()->project_id);
        $this->assertEqualsWithDelta(5000, (float) $invoice->fresh()->total, 0.01);
    }

    public function test_the_field_is_offered_when_projects_is_licensed(): void
    {
        $this->project('APOLLO', 'Apollo rebuild');

        Livewire::test(CreateInvoice::class)
            ->assertSuccessful()
            ->assertSee('Project');
    }

    public function test_the_field_is_withheld_when_projects_is_not(): void
    {
        // Invoicing has to stay sellable on its own. A field that can never be
        // filled is worse than no field.
        $this->unlicenseProjects();

        Livewire::test(CreateInvoice::class)
            ->assertSuccessful()
            ->assertDontSee('Lets you ask what one piece of work has been billed');
    }

    public function test_the_list_still_works_without_projects(): void
    {
        // The column and the filter are both guarded. If either were not, the
        // list would try to eager-load a relation for a module the company does
        // not have and the screen would be the thing that broke.
        $this->invoice(null, 1200);
        $this->unlicenseProjects();

        Livewire::test(ListInvoices::class)->assertSuccessful();
    }

    public function test_an_invoice_keeps_its_project_even_if_the_module_is_switched_off(): void
    {
        // Licensing decides what is offered, not what is stored. Switching the
        // module off must not quietly unpick data already recorded — switching it
        // back on has to find the work where it was left.
        $project = $this->project('APOLLO', 'Apollo rebuild');
        $invoice = $this->invoice($project, 5000);

        $this->unlicenseProjects();

        $this->assertSame($project->getKey(), $invoice->fresh()->project_id);
    }

    public function test_the_saved_invoice_carries_the_project_through_the_form(): void
    {
        $project = $this->project('APOLLO', 'Apollo rebuild');

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'kind' => Invoice::KIND_SALE,
                'contact_id' => $this->client->getKey(),
                'project_id' => $project->getKey(),
                'currency_code' => \App\Modules\Accounting\Models\Currency::baseCode(),
                'invoice_date' => '2026-07-10',
                'subtotal' => 1000,
                'tax_amount' => 0,
                'total' => 1000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($project->getKey(), Invoice::latest('id')->first()->project_id);
    }
}
