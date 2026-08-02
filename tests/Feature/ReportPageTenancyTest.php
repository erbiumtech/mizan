<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The report pages live outside the Filament panel, and everything they print is
 * read from a tenant database. Nothing out there makes a company current, so
 * until the URL carried one these pages ran on the landlord connection — where,
 * in production, there is no chart of accounts at all.
 *
 * The company in the path is also the authorization boundary: it names whose
 * books are being asked for, so the first question is whether the reader may be
 * in that company.
 */
class ReportPageTenancyTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Company $company;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountant = $this->makeUser('Administrator', 'reports@test.local');
        $this->actingAs($this->accountant);
        $this->company = $this->setCurrentTenant();
    }

    private function url(string $name, array $query = []): string
    {
        return route($name, ['company' => $this->company->slug, ...$query]);
    }

    public function test_the_trial_balance_reads_the_company_in_the_url(): void
    {
        // A posted entry, so there is something in this company's ledger that
        // could only have come from its own database.
        $entries = app(JournalEntryService::class);
        $cash = Account::where('code', '1100')->firstOrFail();
        $salaries = Account::where('code', '5100')->firstOrFail();

        $entry = $entries->create(['entry_date' => '2026-07-10'], [
            ['account_id' => $salaries->id, 'debit_amount' => 12345],
            ['account_id' => $cash->id, 'credit_amount' => 12345],
        ]);
        $entries->submitForApproval($entry);
        $entries->approve($entry, $this->makeUser('Manager', 'approver@test.local'));
        $entries->post($entry);

        $this->actingAs($this->accountant);

        $this->get($this->url('reports.trial-balance'))
            ->assertOk()
            ->assertSee($salaries->name)
            ->assertSee(number_format(12345, 2));
    }

    public function test_the_profit_and_loss_opens_too(): void
    {
        $this->get($this->url('reports.profit-and-loss'))
            ->assertOk()
            ->assertSee('Profit');
    }

    public function test_the_pdf_still_renders(): void
    {
        $response = $this->get($this->url('reports.trial-balance', ['format' => 'pdf']))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    /**
     * A company's books are not readable by naming it in a URL. Membership is
     * what decides, the same question the panel asks before letting anyone switch
     * into a company.
     */
    public function test_someone_from_another_company_is_refused(): void
    {
        $url = $this->url('reports.trial-balance');

        $outsider = User::factory()->create(['status' => 1]);
        Company::factory()->create()->users()->attach($outsider);

        $this->actingAs($outsider);

        $this->get($url)->assertForbidden();
    }

    public function test_a_company_that_does_not_exist_is_not_found(): void
    {
        $this->get(route('reports.trial-balance', ['company' => 'no-such-company']))
            ->assertNotFound();
    }

    /**
     * A super admin reaches every company (canAccessTenant), which is what this
     * middleware asks — so they can read any company's books by naming it.
     */
    public function test_a_super_admin_can_read_any_companys_books(): void
    {
        $url = $this->url('reports.trial-balance');

        $superAdmin = User::factory()->create(['status' => 1, 'is_super_admin' => true]);

        $this->actingAs($superAdmin);

        $this->get($url)->assertOk();
    }
}
