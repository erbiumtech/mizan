<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\PettyCashVoucher;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Services\PettyCashService;
use Database\Seeders\TransactionTypeSeeder;
use InvalidArgumentException;
use Tests\AccountingTestCase;

/**
 * Editing a voucher restates its posted 2-line entry in place, so the ledger
 * must move by exactly the amount delta — no reversal, no stray Received row.
 */
class PettyCashVoucherEditTest extends AccountingTestCase
{
    private PettyCashService $service;

    private Account $pettyCash;

    private TransactionType $fuel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TransactionTypeSeeder::class);

        $this->service = app(PettyCashService::class);
        $this->pettyCash = Account::where('code', '1150')->firstOrFail();
        $this->fuel = TransactionType::byCode('fuel');

        $this->service->topUp(now()->startOfMonth()->toDateString(), 4000);
    }

    private function bookVoucher(float $amount = 500, string $details = 'Diesel'): PettyCashVoucher
    {
        return $this->service->bookVoucher([
            'date' => now()->toDateString(),
            'details' => $details,
            'amount' => $amount,
            'transaction_type_id' => $this->fuel->id,
        ]);
    }

    public function test_increasing_the_amount_moves_both_ledger_sides_by_the_delta(): void
    {
        $voucher = $this->bookVoucher(500);
        $expense = $this->fuel->account;

        $balanceBefore = $this->service->balanceAsOf();
        $expenseBefore = (float) $expense->refresh()->balance;

        $this->service->updateVoucher($voucher, ['details' => 'Diesel + oil', 'amount' => 650]);

        $this->assertSame(650.0, (float) $voucher->refresh()->amount);
        $this->assertSame('Diesel + oil', $voucher->details);

        // Petty cash drops by 150, the expense account rises by 150.
        $this->assertSame(round($balanceBefore - 150, 2), $this->service->balanceAsOf());
        $this->assertSame(round($expenseBefore + 150, 2), round((float) $expense->refresh()->balance, 2));

        // The entry is restated, not reversed: still two lines, memo updated.
        $entry = $voucher->journalEntry;
        $this->assertCount(2, $entry->lines);
        $this->assertSame('Diesel + oil', $entry->memo);
        $this->assertSame(650.0, (float) $entry->lines()->where('account_id', $this->pettyCash->id)->value('credit_amount'));
    }

    public function test_decreasing_the_amount_returns_money_to_the_float(): void
    {
        $voucher = $this->bookVoucher(500);
        $balanceBefore = $this->service->balanceAsOf();

        $this->service->updateVoucher($voucher, ['details' => 'Diesel', 'amount' => 200]);

        $this->assertSame(round($balanceBefore + 300, 2), $this->service->balanceAsOf());
    }

    public function test_details_only_edit_leaves_the_balance_untouched(): void
    {
        $voucher = $this->bookVoucher(500);
        $balanceBefore = $this->service->balanceAsOf();

        $this->service->updateVoucher($voucher, ['details' => 'Diesel — corrected', 'amount' => 500]);

        $this->assertSame($balanceBefore, $this->service->balanceAsOf());
        $this->assertSame('Diesel — corrected', $voucher->refresh()->details);
    }

    public function test_an_edit_cannot_overdraw_the_float(): void
    {
        $voucher = $this->bookVoucher(500);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('overdrawn');

        $this->service->updateVoucher($voucher, ['details' => 'Diesel', 'amount' => 9000]);
    }

    public function test_a_replenished_month_is_closed_to_edits(): void
    {
        $voucher = $this->bookVoucher(500);

        Beneficiary::create([
            'name' => 'Custodian',
            'is_petty_cash_custodian' => true,
            'is_active' => true,
        ]);

        $this->service->replenish(now()->startOfMonth());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('replenished');

        $this->service->updateVoucher($voucher, ['details' => 'Diesel', 'amount' => 400]);
    }
}
