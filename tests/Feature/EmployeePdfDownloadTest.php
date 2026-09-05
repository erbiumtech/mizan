<?php

namespace Tests\Feature;

use App\Modules\Employees\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The employee PDF is handed over, not kept.
 *
 * It used to be written to `employees/employee-{id}-{time}.pdf` on the company's disk and served by
 * redirect. The route behind that disk checks membership, so it was never public — but every colleague
 * could fetch it with the path, the path was guessable from the id and the clock, and nothing ever deleted
 * one. A file holding somebody's CNIC, bank account and salary that outlives the click that made it is a
 * liability with no owner.
 */
class EmployeePdfDownloadTest extends AccountingTestCase
{
    use InteractsWithTenant;

    public function test_the_pdf_is_streamed_and_nothing_is_written_to_disk(): void
    {
        Gate::before(fn () => true);
        $this->actingAs($this->makeUser('Administrator', 'pdf-stream@test.local'));
        $this->setCurrentTenant();
        Storage::fake('public');

        $employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'streamed@test.local')->id,
            'employee_id' => 'EMP-STREAM',
            'gender' => 'Male',
            'phone' => '0300-0000000',
        ]);

        Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])
            ->callAction('downloadPdf')
            ->assertFileDownloaded('employee-EMP-STREAM.pdf');

        $this->assertSame([], Storage::disk('public')->allFiles(), 'no copy of the document may be left on disk');
    }
}
