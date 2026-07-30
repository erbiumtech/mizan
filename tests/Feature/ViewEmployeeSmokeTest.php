<?php

namespace Tests\Feature;

use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Core\Models\Company;
use App\Modules\Employees\Models\Employee;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class ViewEmployeeSmokeTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    public function test_view_page_and_pdf_blade_render(): void
    {
        Gate::before(fn () => true);
        $user = User::factory()->create();
        $this->actingAs($user);
        $company = $this->setCurrentTenant();
        app()->instance('currentTenant', $company);

        $emp = Employee::create([
            'user_id' => $user->id, 'employee_id' => 'EMP-A', 'phone' => '111', 'secondary_phone' => '222',
            'gender' => 'Male', 'is_active' => 1, 'nic' => '123',
        ]);

        Livewire::test(ViewEmployee::class, ['record' => $emp->id])->assertSuccessful();

        // PDF blade renders without error.
        $html = view('pdfs.employee', ['employee' => $emp->load('user', 'bank', 'manager.user')])->render();
        $this->assertStringContainsString('Employee Information', $html);
        $this->assertStringContainsString('222', $html); // secondary phone shown
    }
}
