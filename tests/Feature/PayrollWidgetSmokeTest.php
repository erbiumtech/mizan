<?php

namespace Tests\Feature;

use App\Filament\Widgets\PayrollByEmployeeChart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PayrollWidgetSmokeTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    public function test_payroll_widget_renders_without_cross_db_join(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        Livewire::test(PayrollByEmployeeChart::class)->assertSuccessful();
    }
}
