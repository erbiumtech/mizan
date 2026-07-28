<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeChangeRequest;
use Illuminate\Support\Facades\Storage;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * An employee replacing their NIC scans must go through approval like any other
 * self-service edit.
 *
 * Regression: `nic_front` / `nic_back` were missing from ALLOWED_FIELDS, so the
 * upload landed on disk, the paths were then reverted by the interception, and
 * the request carried no trace of it — the new scan was silently lost and
 * approval had nothing to apply.
 */
class EmployeeNicChangeRequestTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::create([
            'user_id' => $this->makeUser('Employee', 'nic-request@erbium.ch')->id,
            'employee_id' => 'EMP-NIC-REQ',
            'gender' => 'Male',
            'phone' => '0300-7770001',
            'secondary_phone' => '0301-7770001',
            'nic' => '12345-1234567-1',
            'nic_front' => 'nic/old-front.png',
            'nic_back' => 'nic/old-back.png',
        ]);
    }

    protected function tearDown(): void
    {
        Storage::disk('public')->deleteDirectory('nic');

        parent::tearDown();
    }

    /** Replaces the front scan as the employee, via the model (as the form does). */
    private function requestNewFrontScan(string $newPath = 'nic/new-front.png'): void
    {
        $this->actingAs($this->employee->user);
        $this->setCurrentTenant();

        $this->employee->update(['nic_front' => $newPath]);
    }

    public function test_nic_scans_are_requestable_fields(): void
    {
        $this->assertContains('nic_front', EmployeeChangeRequest::ALLOWED_FIELDS);
        $this->assertContains('nic_back', EmployeeChangeRequest::ALLOWED_FIELDS);
    }

    public function test_a_new_scan_is_captured_in_the_request_and_not_applied_yet(): void
    {
        $this->requestNewFrontScan();

        $request = EmployeeChangeRequest::sole();
        $this->assertArrayHasKey('nic_front', $request->requested_changes);
        $this->assertSame('nic/new-front.png', $request->requested_changes['nic_front']);
        $this->assertSame('nic/old-front.png', $request->original_values['nic_front']);

        // Untouched until approved.
        $this->assertSame('nic/old-front.png', $this->employee->fresh()->nic_front);
    }

    public function test_approval_applies_the_new_scan(): void
    {
        $this->requestNewFrontScan();

        EmployeeChangeRequest::sole()->approve(
            $this->makeUser('Administrator', 'nic-approver@erbium.ch')
        );

        $this->assertSame('nic/new-front.png', $this->employee->fresh()->nic_front);
    }

    public function test_rejection_keeps_the_previous_scan(): void
    {
        $this->requestNewFrontScan();

        EmployeeChangeRequest::sole()->reject(
            $this->makeUser('Administrator', 'nic-rejecter@erbium.ch'),
            'Unreadable.'
        );

        $this->assertSame('nic/old-front.png', $this->employee->fresh()->nic_front);
    }

    public function test_both_scans_can_be_replaced_at_once(): void
    {
        $this->actingAs($this->employee->user);
        $this->setCurrentTenant();

        $this->employee->update([
            'nic_front' => 'nic/new-front.png',
            'nic_back' => 'nic/new-back.png',
        ]);

        $request = EmployeeChangeRequest::sole();
        $this->assertEqualsCanonicalizing(
            ['nic_front', 'nic_back'],
            array_keys($request->requested_changes)
        );

        $request->approve($this->makeUser('Administrator', 'nic-both@erbium.ch'));

        $fresh = $this->employee->fresh();
        $this->assertSame('nic/new-front.png', $fresh->nic_front);
        $this->assertSame('nic/new-back.png', $fresh->nic_back);
    }

    /** The reviewer's modal must render the scans, not the stored filenames. */
    public function test_the_diff_shows_the_scans_as_images(): void
    {
        $this->actingAs($this->employee->user);
        $this->setCurrentTenant();

        Storage::disk('public')->put('nic/old-front.png', $this->png());
        Storage::disk('public')->put('nic/new-front.png', $this->png());

        $this->requestNewFrontScan();

        $html = view('filament.employee-change-diff', [
            'record' => EmployeeChangeRequest::sole(),
        ])->render();

        $this->assertStringContainsString('NIC (Front)', $html);
        $this->assertStringContainsString('<img src="'.Storage::disk('public')->url('nic/old-front.png'), $html);
        $this->assertStringContainsString('<img src="'.Storage::disk('public')->url('nic/new-front.png'), $html);
        $this->assertStringNotContainsString('file missing', $html);
    }

    /** A path with no file behind it must read as missing, not a broken image. */
    public function test_the_diff_flags_a_scan_that_is_no_longer_on_disk(): void
    {
        $this->requestNewFrontScan('nic/vanished.png');

        $html = view('filament.employee-change-diff', [
            'record' => EmployeeChangeRequest::sole(),
        ])->render();

        $this->assertStringContainsString('file missing', $html);
        $this->assertStringContainsString('nic/vanished.png', $html);
    }

    /** An admin editing the scans still writes straight through. */
    public function test_a_privileged_edit_replaces_the_scan_immediately(): void
    {
        $admin = $this->makeUser('Administrator', 'nic-admin@erbium.ch');
        $this->actingAs($admin);
        $this->setCurrentTenant();

        $this->employee->update(['nic_front' => 'nic/admin-front.png']);

        $this->assertSame('nic/admin-front.png', $this->employee->fresh()->nic_front);
        $this->assertSame(0, EmployeeChangeRequest::count());
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(80, 50);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 205, 210));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }
}
