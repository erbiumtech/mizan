<?php

namespace Tests\Feature;

use App\Filament\Pages\AccountRegister;
use App\Filament\Pages\BankPaymentFile;
use App\Filament\Pages\GnuCashImport;
use App\Filament\Pages\PettyCashBook;
use App\Filament\Pages\ProfitAndLoss;
use App\Filament\Pages\SalaryBankFile;
use App\Filament\Pages\TrialBalance;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentReportPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_report_pages_render(): void
    {
        Gate::before(fn () => true);
        $this->seed(ChartOfAccountsSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $pages = [
            TrialBalance::class,
            ProfitAndLoss::class,
            SalaryBankFile::class,
            PettyCashBook::class,
            BankPaymentFile::class,
            AccountRegister::class,
            GnuCashImport::class,
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
    }
}
