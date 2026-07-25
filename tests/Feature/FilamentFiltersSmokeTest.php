<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentFiltersSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_employee_filters_apply(): void
    {
        Livewire::test(ListEmployees::class)
            ->set('tableFilters.employee_name.value', '1')
            ->assertSuccessful()
            ->set('tableFilters.employee_email.value', '1')
            ->assertSuccessful();
    }

    public function test_payslip_filters_apply(): void
    {
        Livewire::test(ListPayslips::class)
            ->set('tableFilters.employee_id.value', '1')
            ->assertSuccessful()
            ->set('tableFilters.month.value', 'January')
            ->assertSuccessful()
            ->set('tableFilters.fiscal_year_id.value', '1')
            ->assertSuccessful();
    }
}
