<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\DepreciationService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\AccountingTestCase;

class FixedAssetTest extends AccountingTestCase
{
    private DepreciationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DepreciationService::class);
    }

    private function makeAsset(array $overrides = []): FixedAsset
    {
        return FixedAsset::create(array_merge([
            'name' => 'Test Laptop',
            'account_id' => Account::where('code', '1400')->firstOrFail()->id,
            'purchase_date' => '2026-07-01',
            'purchase_cost' => 360000,
            'depreciation_method' => 'straight_line',
            'useful_life_months' => 36,
            'salvage_value' => 0,
        ], $overrides));
    }

    public function test_asset_code_is_auto_generated(): void
    {
        $asset = $this->makeAsset();

        $this->assertMatchesRegularExpression('/^FA-\d{4}$/', $asset->asset_code);
    }

    public function test_straight_line_monthly_depreciation(): void
    {
        $asset = $this->makeAsset(); // (360000 - 0) / 36 = 10000/month

        $this->assertSame(10000.0, $asset->monthlyDepreciation());

        $entry = $this->service->depreciateAsset($asset, Carbon::parse('2026-07-15'));

        $this->assertNotNull($entry);
        $this->assertTrue($entry->is_posted);
        $this->assertSame('adjusting', $entry->entry_type);
        $this->assertSame('2026-07-31', $entry->entry_date->toDateString());
        $this->assertSame(10000.0, (float) $asset->fresh()->accumulated_depreciation);
        $this->assertSame(350000.0, $asset->fresh()->book_value);

        // Ledger: 5990 debit-normal +10000, 1500 credit-normal +10000
        $this->assertSame(10000.0, (float) Account::where('code', '5990')->first()->balance);
        $this->assertSame(10000.0, (float) Account::where('code', '1500')->first()->balance);
    }

    public function test_salvage_value_reduces_depreciable_base(): void
    {
        $asset = $this->makeAsset(['purchase_cost' => 100000, 'salvage_value' => 40000, 'useful_life_months' => 12]);

        $this->assertSame(5000.0, $asset->monthlyDepreciation()); // (100000-40000)/12
    }

    public function test_same_month_cannot_be_depreciated_twice(): void
    {
        $asset = $this->makeAsset();

        $this->assertNotNull($this->service->depreciateAsset($asset, Carbon::parse('2026-07-15')));
        $this->assertNull($this->service->depreciateAsset($asset->fresh(), Carbon::parse('2026-07-20')));
        $this->assertNotNull($this->service->depreciateAsset($asset->fresh(), Carbon::parse('2026-08-15')));
    }

    public function test_asset_not_depreciated_before_purchase(): void
    {
        $asset = $this->makeAsset(['purchase_date' => '2026-10-01']);

        $this->assertNull($this->service->depreciateAsset($asset, Carbon::parse('2026-08-15')));
    }

    public function test_depreciation_stops_at_salvage_and_flips_status(): void
    {
        $asset = $this->makeAsset(['purchase_cost' => 30000, 'useful_life_months' => 3]);

        foreach (['2026-07-15', '2026-08-15', '2026-09-15'] as $month) {
            $this->service->depreciateAsset($asset->fresh(), Carbon::parse($month));
        }

        $asset->refresh();
        $this->assertSame(30000.0, (float) $asset->accumulated_depreciation);
        $this->assertSame(FixedAsset::STATUS_FULLY_DEPRECIATED, $asset->status);
        $this->assertNull($this->service->depreciateAsset($asset, Carbon::parse('2026-10-15')));
    }

    public function test_run_for_month_covers_all_active_assets(): void
    {
        $this->makeAsset(['name' => 'Laptop A']);
        $this->makeAsset(['name' => 'Laptop B']);
        $disposed = $this->makeAsset(['name' => 'Old Printer']);
        $this->service->dispose($disposed);

        $entries = $this->service->runForMonth(Carbon::parse('2026-07-15'), $this->fiscalYear->id);

        $this->assertCount(2, $entries);
    }

    public function test_disposal_writes_off_asset_and_balances(): void
    {
        $asset = $this->makeAsset(); // 360k, 10k/month
        $this->service->depreciateAsset($asset, Carbon::parse('2026-07-15'));

        $entry = $this->service->dispose($asset->fresh(), Carbon::parse('2026-08-01'));

        $this->assertTrue($entry->is_posted);
        $this->assertTrue($entry->isBalanced());

        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($l) => $l->account->code);
        $this->assertSame(10000.0, (float) $lines['1500']->debit_amount);   // clear accumulated
        $this->assertSame(350000.0, (float) $lines['5995']->debit_amount);  // loss = book value
        $this->assertSame(360000.0, (float) $lines['1400']->credit_amount); // remove cost

        $asset->refresh();
        $this->assertSame(FixedAsset::STATUS_DISPOSED, $asset->status);
        $this->assertNotNull($asset->disposed_at);

        // 1500 balance back to zero: credited 10k by depreciation, debited 10k on disposal
        $this->assertSame(0.0, (float) Account::where('code', '1500')->first()->balance);
    }

    public function test_disposed_asset_cannot_be_disposed_again(): void
    {
        $asset = $this->makeAsset();
        $this->service->dispose($asset);

        $this->expectException(InvalidArgumentException::class);

        $this->service->dispose($asset->fresh());
    }

    public function test_depreciation_entries_link_to_asset_via_source(): void
    {
        $asset = $this->makeAsset();
        $this->service->depreciateAsset($asset, Carbon::parse('2026-07-15'));

        $this->assertSame(1, $asset->journalEntries()->count());
        $this->assertInstanceOf(FixedAsset::class, JournalEntry::first()->source);
    }

    public function test_policy_gates_depreciate_and_dispose(): void
    {
        $accountant = $this->makeUser('Accountant', 'fa-acct@test.local');
        $manager = $this->makeUser('Manager', 'fa-mgr@test.local');
        $asset = $this->makeAsset();

        $this->assertFalse($accountant->can('depreciate', $asset));
        $this->assertFalse($accountant->can('dispose', $asset));
        $this->assertTrue($manager->can('depreciate', $asset));
        $this->assertTrue($manager->can('dispose', $asset));
        $this->assertTrue($accountant->can('create', FixedAsset::class));

        $this->service->dispose($asset);
        $this->assertFalse($manager->can('dispose', $asset->fresh()));
        $this->assertFalse($manager->can('update', $asset->fresh()));
    }
}
