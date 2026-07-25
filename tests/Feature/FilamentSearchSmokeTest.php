<?php

namespace Tests\Feature;

use App\Filament\Resources\AnnualTaxes\Pages\ListAnnualTaxes;
use App\Filament\Resources\EmployeeSettings\Pages\ListEmployeeSettings;
use App\Filament\Resources\Payslips\Pages\ListPayslips;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentSearchSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_multi_relation_search_runs(): void
    {
        foreach ([ListAnnualTaxes::class, ListEmployeeSettings::class, ListPayslips::class] as $page) {
            Livewire::test($page)
                ->set('tableSearch', 'EMP-1')
                ->assertSuccessful()
                ->set('tableSearch', 'Alice')
                ->assertSuccessful();
        }
    }
}
