<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\AccountRegister;
use App\Modules\Accounting\Filament\Pages\BankPaymentFile;
use App\Modules\Payroll\Filament\Pages\FbrTaxFile;
use App\Modules\Accounting\Filament\Pages\GnuCashImport;
use App\Modules\Accounting\Filament\Pages\PettyCashBook;
use App\Modules\Accounting\Filament\Pages\ProfitAndLoss;
use App\Modules\Payroll\Filament\Pages\SalaryBankFile;
use App\Modules\Accounting\Filament\Pages\TrialBalance;
use App\Modules\Core\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class FilamentReportPagesSmokeTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    public function test_all_report_pages_render(): void
    {
        Gate::before(fn () => true);
        $this->seed(ChartOfAccountsSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user);

        // With a tenant, because the panel is tenant-scoped and there is no such
        // thing as one of its pages rendering without one. These pages link out to
        // the report routes, which now name the company in the path — a render
        // with no tenant cannot build those URLs, and neither can a browser.
        $this->setCurrentTenant();

        $pages = [
            TrialBalance::class,
            ProfitAndLoss::class,
            SalaryBankFile::class,
            PettyCashBook::class,
            BankPaymentFile::class,
            AccountRegister::class,
            GnuCashImport::class,
            FbrTaxFile::class,
        ];

        $failures = [];
        foreach ($pages as $page) {
            try {
                Livewire::test($page)->assertSuccessful();
            } catch (\Throwable $e) {
                $failures[] = class_basename($page).' → '.$e->getMessage();
            }
        }

        if ($failures) {
            $this->fail("Report page render failures:\n - ".implode("\n - ", $failures));
        }

        $this->addToAssertionCount(1);
    }

    public function test_report_pages_respect_permission_gates(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse(TrialBalance::canAccess());
        $this->assertFalse(ProfitAndLoss::canAccess());
        $this->assertFalse(SalaryBankFile::canAccess());
        $this->assertFalse(PettyCashBook::canAccess());
        $this->assertFalse(BankPaymentFile::canAccess());
        $this->assertFalse(AccountRegister::canAccess());
        $this->assertFalse(GnuCashImport::canAccess());
        $this->assertFalse(FbrTaxFile::canAccess());
    }
}
