<?php

namespace Tests\Feature;

use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Employee;
use App\Multitenancy\Tasks\SwitchTenantFilesystemTask;
use App\Support\Pdf\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The NIC scans appear in two places, and each resolves them differently:
 *
 *  - the view page renders <img> tags whose src comes from the `public` disk,
 *    which now points at the access-checked streaming route rather than a
 *    `public/storage` symlink;
 *  - the PDF reads the bytes straight off the disk and inlines them as data
 *    URIs, so it needs no URL at all.
 *
 * Both broke silently when the disk's URL root changed, hence these.
 */
class EmployeeNicImagesTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'nic-images@erbium.ch')->id,
            'employee_id' => 'EMP-NIC',
            'gender' => 'Male',
            'phone' => '0300-4440001',
            'secondary_phone' => '0301-4440001',
            'nic' => '12345-1234567-1',
            'nic_front' => 'nic/front.png',
            'nic_back' => 'nic/back.png',
        ]);

        $this->actingAs($this->employee->user);
        $company = $this->setCurrentTenant();
        $this->companyId = $company->getKey();

        // The tenant disk must be current *before* writing, so the files land in
        // the same per-company directory the page and the PDF read from.
        (new SwitchTenantFilesystemTask)->makeCurrent($company);

        Storage::disk('public')->put('nic/front.png', $this->png());
        Storage::disk('public')->put('nic/back.png', $this->png());
    }

    protected function tearDown(): void
    {
        Storage::disk('public')->deleteDirectory('nic');

        parent::tearDown();
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(240, 150);
        imagefill($im, 0, 0, imagecolorallocate($im, 225, 228, 232));
        imagefilledrectangle($im, 0, 0, 239, 34, imagecolorallocate($im, 60, 70, 90));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    public function test_the_view_page_shows_both_scans_through_the_streaming_route(): void
    {
        $html = Livewire::test(ViewEmployee::class, ['record' => $this->employee->getKey()])
            ->assertSuccessful()
            ->html();

        $this->assertStringContainsString('NIC Images', $html);
        $this->assertStringContainsString("/files/{$this->companyId}/nic/front.png", $html);
        $this->assertStringContainsString("/files/{$this->companyId}/nic/back.png", $html);

        // Would need the `storage:link` symlink, which is not available on every host.
        $this->assertStringNotContainsString('/storage/nic/', $html);
    }

    public function test_the_pdf_inlines_both_scans(): void
    {
        config(['pdf.driver' => 'dompdf']);

        $html = Pdf::view('pdfs.employee', [
            'employee' => $this->employee->load('user', 'bank', 'manager.user'),
        ])->html();

        $this->assertStringContainsString('NIC Images', $html);
        $this->assertSame(2, substr_count($html, 'data:image/png;base64,'), 'both scans should be inlined');

        // Nothing fetched over HTTP: Dompdf would have to resolve it, and on a
        // host without the symlink there is nothing to resolve.
        $this->assertStringNotContainsString('<img src="/files/', $html);
    }

    public function test_the_pdf_renders_and_omits_the_section_when_there_are_no_scans(): void
    {
        config(['pdf.driver' => 'dompdf']);

        // Bypasses the self-service interception: the acting user is the
        // employee, so a plain update() would become a pending change request
        // and leave the scans in place. This is fixture setup, not a user edit.
        Employee::withoutApprovalRouting(
            fn () => $this->employee->update(['nic_front' => null, 'nic_back' => null])
        );

        $document = Pdf::view('pdfs.employee', [
            'employee' => $this->employee->fresh()->load('user', 'bank', 'manager.user'),
        ]);

        $this->assertStringNotContainsString('NIC Images', $document->html());
        $this->assertStringStartsWith('%PDF-', $document->raw());
    }

    /** A path recorded on the employee but missing on disk must not break either view. */
    public function test_a_missing_file_is_skipped_rather_than_breaking_the_pdf(): void
    {
        config(['pdf.driver' => 'dompdf']);

        Storage::disk('public')->delete('nic/back.png');

        $html = Pdf::view('pdfs.employee', [
            'employee' => $this->employee->load('user', 'bank', 'manager.user'),
        ])->html();

        $this->assertSame(1, substr_count($html, 'data:image/png;base64,'));
        $this->assertStringContainsString('NIC Images', $html);
    }
}
