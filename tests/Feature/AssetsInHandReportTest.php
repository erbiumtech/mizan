<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Pages\AssetsInHand;
use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Modules\Lifecycle\Services\FinalSettlementBuilder;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Assets in employees' hands — `docs/reports-expansion-plan.md` Phase 3.7.
 *
 * The plan names two ties and both are asserted against the thing they tie to rather than against a number
 * typed into this file:
 *
 *  - **settlement recovery** — the report's total is compared against
 *    `FinalSettlementBuilder::unreturnedAssets()`, so the two cannot drift apart;
 *  - **the asset register** — the standing of a capitalised item is compared against the `fixed_assets` row,
 *    including the case the report exists to catch: an asset disposed on the books while somebody still has it.
 *
 * Beyond the ties, the three findings: a holder who has left, an item nobody priced (which settlement recovers
 * *nothing* for, so a nought would be a lie), and the disposal. Each is tested for the reason it is on the
 * report, not for the string it prints.
 */
class AssetsInHandReportTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const AS_OF = '2027-02-20';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(\App\Modules\Core\Models\User::factory()->create(['status' => 1]));
        $this->setCurrentTenant();

        foreach (['lifecycle', 'employees', 'leave', 'advances', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function employee(string $name, ?string $leftOn = null): Employee
    {
        return Employee::create([
            'employee_id' => 'EMP-'.mb_substr(md5($name), 0, 4),
            'name' => $name,
            'date_of_joining' => '2024-01-01',
            'left_on' => $leftOn,
            'status' => $leftOn === null ? 1 : 0,
        ]);
    }

    private function issue(Employee $employee, array $attributes = []): IssuedAsset
    {
        return IssuedAsset::create(array_merge([
            'employee_id' => $employee->getKey(),
            'asset_kind' => 'laptop',
            'description' => 'ThinkPad T14',
            'serial_no' => 'SN-001',
            'issued_on' => '2026-08-01',
            'value' => 180_000,
        ], $attributes));
    }

    private function fixedAsset(string $status = FixedAsset::STATUS_ACTIVE): FixedAsset
    {
        $account = Account::firstOrCreate(
            ['code' => '1500'],
            ['name' => 'Fixed Assets', 'type' => 'asset', 'is_active' => true],
        );

        return FixedAsset::create([
            'asset_code' => 'FA-'.$status,
            'name' => 'ThinkPad T14',
            'account_id' => $account->getKey(),
            'purchase_date' => '2026-07-01',
            'purchase_cost' => 200_000,
            'useful_life_months' => 36,
            'status' => $status,
            'disposed_at' => $status === FixedAsset::STATUS_DISPOSED ? '2027-01-15' : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('AssetsInHand', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for AssetsInHand');

        return $payload;
    }

    private function cell(array $payload, string $column, int $row = 0): string
    {
        $index = array_search($column, $payload['columns'], true);
        $this->assertNotFalse($index, "there is no {$column} column");

        return $payload['rows'][$row][$index];
    }

    // ──────────────────────────────────────────────── what is listed at all ──

    /** Outstanding means not returned. Kit that came back is not out. */
    public function test_a_returned_asset_is_not_listed(): void
    {
        $this->issue($this->employee('Ayesha'), ['returned_on' => '2026-11-01']);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NOTHING IS OUT WITH ANYBODY', $payload['note']);
        $this->assertSame(0.0, $payload['tiles'][0]['value']);
    }

    /**
     * Nor is one issued after the date being read.
     *
     * The report is an as-at, so reading last quarter must not show a laptop handed out this week. Without the
     * date filter the report would answer "what is out now" whatever date was asked for.
     */
    public function test_an_asset_issued_after_the_date_is_not_listed(): void
    {
        $this->issue($this->employee('Bilal'), ['issued_on' => '2027-03-01']);

        $this->assertSame([], $this->report()['rows']);
    }

    /** An item still out is listed with the thing somebody needs to find it: kind, description and serial. */
    public function test_an_outstanding_asset_is_listed_with_its_serial(): void
    {
        $this->issue($this->employee('Ayesha'), ['serial_no' => 'PF-9K2LM']);

        $payload = $this->report();

        $this->assertCount(1, $payload['rows']);
        $this->assertSame('Laptop · ThinkPad T14', $this->cell($payload, 'Item'));
        $this->assertSame('PF-9K2LM', $this->cell($payload, 'Serial'));
        $this->assertStringContainsString('Ayesha', $this->cell($payload, 'Holder'));
    }

    /** Days out is counted to the date being read, not to today. */
    public function test_days_out_is_counted_to_the_date_being_read(): void
    {
        $this->issue($this->employee('Ayesha'), ['issued_on' => '2026-08-01']);

        // 1 August 2026 to 20 February 2027.
        $this->assertSame('203', $this->cell($this->report(), 'Days out'));
        $this->assertSame('31', $this->cell($this->report('2026-09-01'), 'Days out'));
    }

    // ───────────────────────────────────── the tie to settlement recovery ──

    /**
     * The total is what a final settlement would actually recover.
     *
     * Not a comparable figure — the *same* figure. `unreturnedAssets()` sums `value` over outstanding items
     * for one employee, and this report sums the same column over the same scope for everybody. Asserted
     * against the builder rather than against a literal so the two cannot drift apart: if somebody changes
     * what settlement charges for, this test fails and the report is wrong.
     */
    public function test_the_value_out_is_exactly_what_settlement_would_recover(): void
    {
        $ayesha = $this->employee('Ayesha');
        $this->issue($ayesha, ['value' => 180_000]);
        $this->issue($ayesha, ['asset_kind' => 'phone', 'description' => 'Pixel 8', 'value' => 95_000]);

        $recovered = app(FinalSettlementBuilder::class)->unreturnedAssets($ayesha);

        $this->assertSame(275_000.0, $recovered, 'the builder charges both items');
        $this->assertSame($recovered, $this->report()['tiles'][0]['value']);
    }

    /**
     * An item nobody priced shows a dash, and settlement recovers nothing for it.
     *
     * The finding, and the reason the cell is not a nought: `unreturnedAssets()` sums the column, so a null
     * contributes nothing — the laptop is gone and the settlement charges for none of it. Printing "0" would
     * read as kit that is genuinely worthless rather than kit nobody valued.
     */
    public function test_an_unvalued_asset_shows_a_dash_and_is_called_out(): void
    {
        $ayesha = $this->employee('Ayesha');
        $this->issue($ayesha, ['value' => null]);

        $payload = $this->report();

        $this->assertSame('—', $this->cell($payload, 'Value'));
        $this->assertSame(0.0, $payload['tiles'][0]['value'], 'settlement recovers nothing for it');
        $this->assertSame(0.0, app(FinalSettlementBuilder::class)->unreturnedAssets($ayesha));
        $this->assertStringContainsString(
            '1 WITH NO VALUE RECORDED, SO A SETTLEMENT RECOVERS NOTHING FOR IT',
            $payload['note'],
        );
    }

    /** And a priced item is not reported as unvalued. */
    public function test_a_priced_asset_is_not_called_out_as_unvalued(): void
    {
        $this->issue($this->employee('Ayesha'), ['value' => 180_000]);

        $this->assertStringNotContainsString('NO VALUE RECORDED', $this->report()['note']);
    }

    // ───────────────────────────────────────────── a holder who has left ──

    /**
     * Somebody who has left and still has the laptop is marked, counted and totalled apart.
     *
     * The most urgent row on the report: the company is not getting it back by asking, and if the settlement
     * is already paid the recovery has been missed.
     */
    public function test_an_asset_held_by_a_leaver_is_marked_and_totalled_apart(): void
    {
        $this->issue($this->employee('Danish', leftOn: '2026-12-31'));

        $payload = $this->report();

        $this->assertStringContainsString('LEFT', $this->cell($payload, 'Holder'));
        $this->assertSame(180_000.0, $payload['tiles'][1]['value'], 'held by leavers');
        $this->assertStringContainsString('1 IS WITH SOMEBODY WHO HAS LEFT', $payload['note']);
    }

    /** A current employee's kit is not in the leaver figure — it is simply in use. */
    public function test_a_current_employees_asset_is_not_in_the_leaver_figure(): void
    {
        $this->issue($this->employee('Ayesha'));

        $payload = $this->report();

        $this->assertSame(0.0, $payload['tiles'][1]['value']);
        $this->assertStringNotContainsString('LEFT', $this->cell($payload, 'Holder'));
        $this->assertStringNotContainsString('HAS LEFT', $payload['note']);
    }

    /**
     * "Left" is judged as at the date being read, not as at today.
     *
     * A register read for September must not mark somebody a leaver who was still employed then and resigned
     * in December. Reading `status` or "is inactive now" instead of the date would get this wrong.
     */
    public function test_a_leaver_is_judged_as_at_the_date_being_read(): void
    {
        $this->issue($this->employee('Danish', leftOn: '2026-12-31'));

        $this->assertStringNotContainsString('LEFT', $this->cell($this->report('2026-09-01'), 'Holder'));
        $this->assertSame(0.0, $this->report('2026-09-01')['tiles'][1]['value']);

        $this->assertStringContainsString('LEFT', $this->cell($this->report('2027-01-05'), 'Holder'));
    }

    /**
     * Somebody's last day is not yet a leaver, which is how the rest of the application reads `left_on`.
     *
     * `HeadcountReports::headcountAt()` counts an employee whose `left_on` is the date being read, so this
     * report has to agree or the same person is on the payroll in one report and gone in another on the same
     * day. It is the right reading here anyway: somebody still in the building can hand the laptop back.
     */
    public function test_the_last_day_of_employment_is_not_yet_a_leaver(): void
    {
        $this->issue($this->employee('Danish', leftOn: '2026-12-31'));

        $this->assertStringNotContainsString('LEFT', $this->cell($this->report('2026-12-31'), 'Holder'));
        $this->assertSame(0.0, $this->report('2026-12-31')['tiles'][1]['value']);

        $this->assertStringContainsString('LEFT', $this->cell($this->report('2027-01-01'), 'Holder'));
    }

    /** Leavers sort to the top, because that is the row somebody has to act on. */
    public function test_leavers_are_listed_first(): void
    {
        // The current employee's laptop went out earlier, so an ordering by date alone would put it first.
        $this->issue($this->employee('Ayesha'), ['issued_on' => '2026-07-01']);
        $this->issue($this->employee('Danish', leftOn: '2026-12-31'), ['issued_on' => '2026-11-01']);

        $payload = $this->report();

        $this->assertStringContainsString('Danish', $this->cell($payload, 'Holder', 0));
        $this->assertStringContainsString('Ayesha', $this->cell($payload, 'Holder', 1));
    }

    /** Within the same standing, the longest-outstanding item is first. */
    public function test_the_longest_outstanding_item_is_first(): void
    {
        $ayesha = $this->employee('Ayesha');
        $this->issue($ayesha, ['description' => 'Newer', 'issued_on' => '2026-12-01']);
        $this->issue($ayesha, ['description' => 'Older', 'issued_on' => '2026-07-05']);

        $payload = $this->report();

        $this->assertStringContainsString('Older', $this->cell($payload, 'Item', 0));
        $this->assertStringContainsString('Newer', $this->cell($payload, 'Item', 1));
    }

    // ────────────────────────────────────────── the tie to the register ──

    /** Kit that was never capitalised is ordinary, and says so rather than looking like a missing link. */
    public function test_an_uncapitalised_asset_says_so(): void
    {
        $this->issue($this->employee('Ayesha'), ['fixed_asset_id' => null]);

        $payload = $this->report();

        $this->assertSame('Not capitalised', $this->cell($payload, 'Register'));
        $this->assertStringNotContainsString('DISPOSED', $payload['note']);
    }

    /** Kit that is on the books is shown against it. */
    public function test_a_capitalised_asset_is_shown_against_the_register(): void
    {
        $this->issue($this->employee('Ayesha'), ['fixed_asset_id' => $this->fixedAsset()->getKey()]);

        $this->assertSame('On the register', $this->cell($this->report(), 'Register'));
    }

    /**
     * An asset disposed on the books while somebody still holds it is the finding.
     *
     * The company's accounts say it no longer owns the laptop. Somebody has the laptop. Nothing else in the
     * application puts those two facts next to each other.
     */
    public function test_an_asset_disposed_while_still_out_is_called_out(): void
    {
        $this->issue($this->employee('Ayesha'), [
            'fixed_asset_id' => $this->fixedAsset(FixedAsset::STATUS_DISPOSED)->getKey(),
        ]);

        $payload = $this->report();

        $this->assertSame('Disposed', $this->cell($payload, 'Register'));
        $this->assertStringContainsString('1 DISPOSED ON THE ASSET REGISTER WHILE STILL OUT', $payload['note']);
    }

    /** A fully depreciated asset is still owned — worth nothing on the books is not the same as gone. */
    public function test_a_fully_depreciated_asset_is_still_on_the_register(): void
    {
        $this->issue($this->employee('Ayesha'), [
            'fixed_asset_id' => $this->fixedAsset(FixedAsset::STATUS_FULLY_DEPRECIATED)->getKey(),
        ]);

        $payload = $this->report();

        $this->assertSame('On the register', $this->cell($payload, 'Register'));
        $this->assertStringNotContainsString('DISPOSED', $payload['note']);
    }

    /**
     * With accounting disabled the register cannot be consulted, and the report does not guess.
     *
     * A real state: a company can turn the module off and still hold `fixed_asset_id` values from before it
     * did. Saying "On the register" then would assert something nothing verified.
     */
    public function test_without_accounting_the_register_is_not_consulted(): void
    {
        $this->issue($this->employee('Ayesha'), ['fixed_asset_id' => $this->fixedAsset()->getKey()]);

        CompanyModule::where('company_id', $this->tenant->getKey())
            ->where('module', 'accounting')
            ->update(['enabled' => false]);
        modules()->flush();

        $payload = $this->report();

        $this->assertSame('Not on register', $this->cell($payload, 'Register'));
        // Still listed, and still valued: the laptop is out whether or not the books are readable.
        $this->assertSame(180_000.0, $payload['tiles'][0]['value']);
    }

    // ───────────────────────────────────────────────── the footer and note ──

    /** The footer totals the value column and counts the items. */
    public function test_the_footer_totals_the_value_out(): void
    {
        $ayesha = $this->employee('Ayesha');
        $this->issue($ayesha, ['value' => 180_000]);
        $this->issue($ayesha, ['asset_kind' => 'phone', 'description' => 'Pixel 8', 'value' => 95_000]);

        $payload = $this->report();
        $index = array_search('Value', $payload['columns'], true);

        $this->assertSame('275,000', $payload['footer'][$index]);
        $this->assertSame('Total — 2 items', $payload['footer'][0]);
    }

    /** All three findings appear together when all three are present. */
    public function test_the_note_states_every_finding_at_once(): void
    {
        $this->issue($this->employee('Danish', leftOn: '2026-12-31'), ['value' => null]);
        $this->issue($this->employee('Ayesha'), [
            'fixed_asset_id' => $this->fixedAsset(FixedAsset::STATUS_DISPOSED)->getKey(),
        ]);

        $note = $this->report()['note'];

        $this->assertStringContainsString('2 ITEMS OUT', $note);
        $this->assertStringContainsString('1 IS WITH SOMEBODY WHO HAS LEFT', $note);
        $this->assertStringContainsString('NO VALUE RECORDED', $note);
        $this->assertStringContainsString('DISPOSED ON THE ASSET REGISTER', $note);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->issue($this->employee('Ayesha'));

        $onThePage = Livewire::test(AssetsInHand::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('AssetsInHand', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertFalse(AssetsInHand::canAccess());
    }
}
