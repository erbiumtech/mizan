<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Widgets\AccountBalancesOverview;
use App\Modules\Accounting\Filament\Widgets\CashFlowChart;
use App\Modules\Accounting\Filament\Widgets\OperationsOverview;
use App\Modules\Core\Models\User;
use App\Modules\Invoicing\Filament\Widgets\ReceivablesPayablesOverview;
use App\Modules\Payroll\Filament\Widgets\PayrollByEmployeeChart;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentWidgetsSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_dashboard_widgets_render(): void
    {
        Gate::before(fn () => true);
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $widgets = [
            OperationsOverview::class,
            AccountBalancesOverview::class,
            CashFlowChart::class,
            PayrollByEmployeeChart::class,
            ReceivablesPayablesOverview::class,
        ];
        $failures = [];
        foreach ($widgets as $w) {
            try {
                Livewire::test($w)->assertSuccessful();
            } catch (\Throwable $e) {
                $failures[] = class_basename($w).' → '.$e->getMessage();
            }
        }
        if ($failures) {
            $this->fail("Widget render failures:\n - ".implode("\n - ", $failures));
        }
        $this->addToAssertionCount(1);
    }
}
