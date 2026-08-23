<?php

namespace Database\Seeders;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Models\ForecastLine;
use App\Modules\ConstructionCosting\Models\JobBudget;
use App\Modules\ConstructionCosting\Models\Trade;
use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\ConstructionCosting\Services\BudgetService;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use App\Modules\ConstructionCosting\Services\CostLedger;
use App\Modules\ConstructionCosting\Services\EarnedValue;
use App\Modules\ConstructionCosting\Services\ForecastService;
use App\Modules\ConstructionCosting\Services\GoodsReceiptService;
use App\Modules\ConstructionCosting\Services\InvoiceAllocationService;
use App\Modules\ConstructionCosting\Services\LabourRateService;
use App\Modules\ConstructionCosting\Services\LabourRecordService;
use App\Modules\ConstructionCosting\Services\PeriodCloseService;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\TenantTransaction;
use Illuminate\Database\Seeder;

/**
 * One job, costed over six months, so every column of the job cost report has something true in it.
 *
 * A worked example rather than scattered fixtures: buy the land, pay the development authority, budget the
 * work month by month, order material, receive part of it, book labour at a rate, measure progress, forecast
 * the outturn. Every figure arrives through the same service the panel uses, so this doubles as a readable
 * answer to "how is a project costed here" — nothing is inserted behind the models' backs.
 *
 * **Every date is derived from `PERIODS`, and nothing reads `now()`.** The first version of this seeder let
 * `GoodsReceiptService::create()` default `received_on` to today, which stranded the material four periods
 * away from the rest of the job and made the seeded figures depend on the day it was run. A demo whose
 * numbers move is a demo nobody can check.
 *
 * **The budget is time-phased on purpose.** `EarnedValue::isTimePhased()` looks for `period_start` on the
 * baseline lines, and without it the report prints "Schedule performance unavailable" and no planned value
 * exists — so SPI, the single number that says whether a job is late, is unavailable. Phasing the budget is
 * what turns the earned-value half of the report on.
 *
 * **Why land and approvals are `other` and not a cost type of their own.** `cost_type` is an enum in five
 * migrations, and this codebase already records what changing one costs: "a table rebuild on MySQL and
 * unsupported on SQLite". Land is not labour, material, plant or subcontract, so it is `other` — and the
 * distinction a developer reports on is carried by `icms_category = 'A'` (Acquisition), which §2.1 already
 * provides and which separates land and approvals from construction cost.
 *
 * Run against a tenant, not the landlord:
 *
 *     php artisan tenants:artisan "db:seed --class=ConstructionDemoSeeder"
 *
 * Two things it deliberately does **not** do. It does not close a cost period: closing runs a checklist and
 * is an operational act, and a demo that force-closed past its own checks would teach the opposite of what
 * the checklist is for. And it leaves every entry `gl_treatment = pending`, which is the honest state — §3.2
 * defines `pending` as "should have reached the GL, has not yet", and posting needs the account mapping a
 * company configures rather than one a seeder invents.
 */
class ConstructionDemoSeeder extends Seeder
{
    private const JOB_CODE = 'J-2026-001';

    /** The job's six months. Every date below is one of these or an offset inside one. */
    private const PERIODS = ['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01'];

    public function run(): void
    {
        // The library first: `CostLedger::record()` refuses a heading and needs a leaf, so there is nothing
        // to book against until it exists.
        $this->call(ConstructionCostCodeSeeder::class);

        // The construction accounts (retention receivable, materials on site, the recovery accounts). §18.2
        // keeps them out of every company's chart, so a construction job is exactly when they are wanted.
        $this->call(ConstructionAccountsSeeder::class);

        if (Job::where('code', self::JOB_CODE)->exists()) {
            $this->command?->warn('  '.self::JOB_CODE.' already exists — leaving it alone.');

            return;
        }

        /*
         * All of it or none of it.
         *
         * Learned the hard way: an unrelated bug threw part-way through the accrual unwind and left this
         * tenant holding a job with 39 cost entries, no progress measurements, no forecast and the steel
         * counted twice — 60,032,010.80 against a true figure of 49,312,010.80. The guard above then refused
         * to touch it, because a job with that code existed. A half-seeded demo is worse than a failed one:
         * it looks finished and every number in it is wrong.
         */
        $job = TenantTransaction::run(function (): Job {
            $job = $this->job();

            $this->budget($job);

            $this->buyLand($job);
            $this->payTheAuthority($job);
            $this->excavate($job);
            $this->superviseEveryMonth($job);
            $this->invoiceTheSteel($job, $this->orderAndReceiveSteel($job));
            $this->orderAndReceiveConcrete($job);
            $this->fixSteel($job);
            $this->plaster($job);

            $this->unwindTheAccruals();
            $this->measureProgress($job);
            $this->forecast($job);

            return $job;
        });

        $this->report($job);
    }

    private function job(): Job
    {
        return Job::create([
            'code' => self::JOB_CODE,
            'name' => 'Green Acres — Block A',
            'description' => 'Ground plus four residential block, 24 apartments, on own land.',
            'nature' => 'building',
            'status' => Job::STATUS_IN_PROGRESS,
            'currency_code' => 'PKR',
            'site_city' => 'Lahore',
            'site_country' => 'PK',
            'commencement_date' => self::PERIODS[0],
            'planned_completion_date' => '2027-06-30',
            // Own development rather than a contract for a third party, so there is no employer, no
            // certificate and no retention — the money goes out rather than being claimed back.
            'contract_sum' => 0,
            'retention_pct' => 0,
        ]);
    }

    private function code(string $code): CostCode
    {
        return CostCode::where('code', $code)->sole();
    }

    /** A day inside one of the job's months, so no date is ever relative to today. */
    private function dayIn(int $period, int $day): string
    {
        return substr(self::PERIODS[$period], 0, 8).str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    /**
     * The original budget, phased by month, approved and baselined.
     *
     * Phased because planned value is the budget's shape over time and nothing else: a lump sum against a
     * code says what the job should cost but not what should have been spent *by now*, which is the only
     * question schedule variance answers. Baselined because earned value measures against a baseline that
     * does not move — a variance against a budget revised every month is not a variance.
     */
    private function budget(Job $job): void
    {
        $budgets = app(BudgetService::class);
        $version = $budgets->createVersion($job, 'Original budget', JobBudget::KIND_ORIGINAL, copyCurrent: false);

        // code, quantity, unit, rate, period index, description
        $lines = [
            ['01.100', 1, 'sum', 24_000_000, 0, 'Plot 412-B, 2 kanal'],
            ['01.110', 1, 'sum', 1_200_000, 0, 'Transfer duty and registration'],
            ['01.200', 1, 'sum', 1_800_000, 1, 'LDA approval'],
            // Budgeted, unlike the first version of this seeder — a fee that is costed and not budgeted
            // shows on the report as an overrun that never was.
            ['01.210', 1, 'sum', 200_000, 1, 'Plan scrutiny and map fee'],
            ['02.200', 1_400, 'm3', 320, 2, 'Basement and footings excavation'],
            ['03.200', 40, 't', 268_000, 3, 'Reinforcement steel, first delivery'],
            ['03.200', 42, 't', 268_000, 4, 'Reinforcement steel, second delivery'],
            ['03.300', 40, 't', 22_000, 3, 'Steel fixing, substructure'],
            ['03.300', 42, 't', 22_000, 4, 'Steel fixing, superstructure'],
            ['03.100', 300, 'm3', 21_500, 4, 'Ready-mix, frame'],
            ['03.100', 320, 'm3', 21_500, 5, 'Ready-mix, slabs'],
            ['04.100', 96_000, 'no', 22, 5, 'Blocks, superstructure'],
            ['04.300', 2_400, 'm2', 260, 5, 'Masonry labour'],
            ['05.100', 2_400, 'm2', 190, 5, 'Plaster and render'],
        ];

        // Supervision runs the whole job, so it is phased evenly rather than dropped on one month.
        foreach (array_keys(self::PERIODS) as $period) {
            $lines[] = ['08.100', 600, 'hr', 950, $period, 'Site supervision'];
        }

        foreach ($lines as [$code, $quantity, $unit, $rate, $period, $description]) {
            $budgets->addLine($version, [
                'cost_code_id' => $this->code($code)->getKey(),
                'quantity' => $quantity,
                'unit_of_measure' => $unit,
                'unit_rate' => $rate,
                'amount' => $quantity * $rate,
                'period_start' => self::PERIODS[$period],
                'description' => $description,
            ]);
        }

        $budgets->setBaseline($budgets->approve($version->refresh()));
    }

    private function cost(Job $job, string $code, array $attributes): void
    {
        app(CostLedger::class)->record($job, $this->code($code), $attributes);
    }

    /**
     * The land, as a direct cost entry.
     *
     * No purchase order: a plot is bought once on a sale deed, and raising a commitment to relieve
     * immediately would put a number in the committed column that was never an obligation.
     */
    private function buyLand(Job $job): void
    {
        $this->cost($job, '01.100', [
            'amount' => 24_650_000,
            'quantity' => 1,
            'unit_of_measure' => 'sum',
            'unit_rate' => 24_650_000,
            'incurred_on' => $this->dayIn(0, 10),
            'description' => 'Plot 412-B — sale deed',
            'reference' => 'DEED-412B',
        ]);

        $this->cost($job, '01.110', [
            'amount' => 1_306_450,
            'incurred_on' => $this->dayIn(0, 14),
            'description' => 'Transfer duty, stamp paper and registration',
            'reference' => 'REG-2026-8841',
        ]);
    }

    /** The development authority: a fee, a receipt, and a cost against the job that paid it. */
    private function payTheAuthority(Job $job): void
    {
        $this->cost($job, '01.200', [
            'amount' => 1_742_000,
            'incurred_on' => $this->dayIn(1, 5),
            'description' => 'LDA building plan approval — Block A',
            'reference' => 'LDA-BP-2026-1174',
        ]);

        $this->cost($job, '01.210', [
            'amount' => 214_500,
            'incurred_on' => $this->dayIn(1, 5),
            'description' => 'Plan scrutiny and map fee',
            'reference' => 'LDA-SC-2026-0913',
        ]);
    }

    /** Plant, measured — 1,400 m³ at 335 against a budget rate of 320, which is the overrun to notice. */
    private function excavate(Job $job): void
    {
        $this->cost($job, '02.200', [
            'amount' => 469_000,
            'quantity' => 1_400,
            'unit_of_measure' => 'm3',
            'unit_rate' => 335,
            'incurred_on' => $this->dayIn(2, 18),
            'description' => 'Excavator and tipper hire — basement',
            'reference' => 'PLANT-2026-031',
        ]);
    }

    /** Supervision every month, so the labour column is not one spike in one period. */
    private function superviseEveryMonth(Job $job): void
    {
        foreach (array_keys(self::PERIODS) as $period) {
            $this->cost($job, '08.100', [
                'amount' => 576_000,
                'quantity' => 600,
                'unit_of_measure' => 'hr',
                'unit_rate' => 960,
                'incurred_on' => $this->dayIn($period, 28),
                'description' => 'Site engineer and foreman',
            ]);
        }
    }

    /**
     * Material the way §5 intends it: order, then receive.
     *
     * The order puts all 82 t in the **committed** column — money promised to a supplier and no longer
     * available to spend twice. Receiving 40 t of it raises an **accrual** at order rate and relieves the
     * commitment by the same amount, so the two columns never carry the same steel. The accrual stays an
     * accrual until the supplier's invoice is allocated, which is §5's whole point: the cost is known before
     * the paperwork arrives, and a report that waited for the invoice would understate the job all month.
     */
    private function orderAndReceiveSteel(Job $job): CommitmentLine
    {
        $orders = app(CommitmentService::class);
        $receipts = app(GoodsReceiptService::class);

        $order = $orders->create([
            'type' => Commitment::TYPE_PURCHASE_ORDER,
            'description' => 'Reinforcement steel — Block A',
            'order_date' => $this->dayIn(3, 2),
        ]);

        $orders->addLine($order, $job, $this->code('03.200'), [
            'description' => 'Reinforcement steel, high tensile, 16mm and 20mm',
            'quantity' => 82,
            'unit_of_measure' => 't',
            'rate' => 268_000,
        ]);

        $orders->issue($orders->approve($order->refresh())->refresh());

        // `received_on` passed explicitly: the service defaults it to today, which is how the first version
        // of this seeder put the steel four months adrift of the job.
        $receipt = $receipts->create($order->refresh(), [
            'delivery_note_reference' => 'DN-2026-0447',
            'received_on' => $this->dayIn(3, 20),
        ]);

        $orderLine = $order->lines()->orderBy('id')->sole();

        $receipts->addLineFor($receipt, $orderLine, 40);
        $receipts->post($receipt->refresh());

        return $orderLine;
    }

    /**
     * The supplier's invoice for the steel already received, which turns the accrual into actual cost.
     *
     * This is the step whose absence flattered the whole report. `EarnedValue::actualCost()` excludes
     * accruals on purpose — §3.5 keeps the columns apart so CPI does not move when nothing happened on site
     * — so while the steel sat accrued it was earning value against no actual cost, and the cost performance
     * index read 1.92 on a job that is in fact slightly over. Allocating the invoice against the commitment
     * line is what closes §5's three-way match: ordered, received, invoiced.
     */
    private function invoiceTheSteel(Job $job, CommitmentLine $orderLine): void
    {
        $allocations = app(InvoiceAllocationService::class);

        // The Invoicing module owns the invoice. Without it the job is still fully costed — the steel simply
        // stays accrued, which is precisely what §5 says an uninvoiced receipt is, so this is a narrower demo
        // rather than a broken one.
        if (! $allocations->isAvailable()) {
            $this->command?->warn('  Invoicing is off, so the steel stays accrued rather than invoiced.');

            return;
        }

        $net = 40 * 268_000.0;

        $supplier = Contact::firstOrCreate(['name' => 'Ittefaq Steel Works'], ['kind' => 'supplier']);

        $invoice = Invoice::create([
            'kind' => Invoice::KIND_PURCHASE,
            'status' => Invoice::STATUS_DRAFT,
            'contact_id' => $supplier->getKey(),
            'invoice_date' => $this->dayIn(3, 28),
            'subtotal' => $net,
            'tax_amount' => 0,
            'total' => $net,
        ]);

        $line = $invoice->lines()->create([
            'description' => 'Reinforcement steel, high tensile — 40 t',
            'quantity' => 40,
            'unit_price' => 268_000,
            'line_total' => $net,
        ]);

        $allocations->allocate($invoice->refresh(), $job, $this->code('03.200'), $net, [
            'invoice_line_id' => $line->getKey(),
            'commitment_line_id' => $orderLine->getKey(),
            'quantity' => 40,
            'description' => 'Steel invoice against PO — first delivery',
        ]);
    }

    private function orderAndReceiveConcrete(Job $job): void
    {
        $orders = app(CommitmentService::class);
        $receipts = app(GoodsReceiptService::class);

        $order = $orders->create([
            'type' => Commitment::TYPE_PURCHASE_ORDER,
            'description' => 'Ready-mix concrete — Block A frame',
            'order_date' => $this->dayIn(4, 3),
        ]);

        $orders->addLine($order, $job, $this->code('03.100'), [
            'description' => 'Ready-mix, grade 3000, pumped',
            'quantity' => 620,
            'unit_of_measure' => 'm3',
            'rate' => 21_800,
        ]);

        $orders->issue($orders->approve($order->refresh())->refresh());

        $receipt = $receipts->create($order->refresh(), [
            'delivery_note_reference' => 'DN-2026-0512',
            'received_on' => $this->dayIn(4, 26),
        ]);

        $receipts->addLineFor($receipt, $order->lines()->orderBy('id')->sole(), 300);
        $receipts->post($receipt->refresh());
    }

    /**
     * Steel fixing, priced by the rate table rather than by hand.
     *
     * §7's point: the rate is resolved at approval and snapshotted onto the record, so re-rating the trade
     * next year does not restate what these days cost. The burden is a separate entry against the same code
     * (§7.3) — an absorbed rate is not a payment to anybody, and folding the two together hides which is which.
     */
    private function fixSteel(Job $job): void
    {
        $trade = Trade::firstOrCreate(['code' => 'STF'], ['name' => 'Steel fixer']);

        $rates = app(LabourRateService::class);

        // Only if the trade has no rate in force. `set()` refuses to create a second overlapping rate — two
        // rates at once leaves nothing saying which one a cost report used — and a company that already
        // priced its steel fixers should keep its own figure rather than have a demo overwrite it.
        if ($rates->resolve(job: $job, trade: $trade, worker: null, on: self::PERIODS[0]) === null) {
            $rates->set(
                ['trade_id' => $trade->getKey()],
                480,
                self::PERIODS[0],
                ['overtime_multiplier' => 1.5, 'burden_percent' => 12],
            );
        }

        $sheets = app(LabourRecordService::class);
        $code = $this->code('03.300');

        $crew = [['W-001', 'Karim Bux'], ['W-002', 'Rashid Ali'], ['W-003', 'Nadeem Iqbal']];

        // Two days in each of the two months the budget phases fixing into.
        foreach ([[3, 12], [3, 13], [4, 9], [4, 10]] as [$period, $day]) {
            foreach ($crew as $index => [$workerCode, $name]) {
                $worker = Worker::firstOrCreate(
                    ['code' => $workerCode],
                    ['name' => $name, 'trade_id' => $trade->getKey(), 'engagement' => Worker::ENGAGEMENT_DIRECT],
                );

                $sheets->approve($sheets->record($worker, $job, $code, [
                    'worked_on' => $this->dayIn($period, $day),
                    'normal_minutes' => 480,
                    // The chargehand works over; the other two do not.
                    'overtime_minutes' => $index === 0 ? 120 : 0,
                ]));
            }
        }
    }

    /** A subcontract package, so the report's cost-type split has all five kinds in it. */
    private function plaster(Job $job): void
    {
        $this->cost($job, '05.100', [
            'amount' => 156_000,
            'quantity' => 800,
            'unit_of_measure' => 'm2',
            'unit_rate' => 195,
            'incurred_on' => $this->dayIn(5, 22),
            'description' => 'Plaster and render — first lift',
            'reference' => 'SC-2026-014',
        ]);
    }

    /**
     * Open the month after the steel delivery, which is what clears its accrual.
     *
     * §4.5, and the reason allocating an invoice does **not** reverse the receipt's accrual itself: accruals
     * unwind when the next period opens, so that the reversal is one auditable run rather than a side effect
     * scattered across every allocation. Skipping this step left the 40 t standing as *both* an accrual and
     * an invoiced actual — 21,440,000 of steel on a 21,976,000 budget, which drove the remaining budget down
     * to 536,000 and made the forecast refuse to write a cost to complete below the open commitment.
     *
     * The June accrual for concrete survives, correctly: it was received and has not been invoiced, so an
     * accrual is exactly what it is. `open()` re-accrues precisely that case.
     */
    private function unwindTheAccruals(): void
    {
        app(PeriodCloseService::class)->open(self::PERIODS[4]);
    }

    /**
     * Physical progress, per code, per month — which is what earned value *is*.
     *
     * Without these the report shows an earned value of zero and a cost performance index of zero, which
     * reads as a catastrophic project rather than an unmeasured one. §14: earned value is percent complete
     * times the baseline budget, and never a function of what was spent.
     *
     * **Each code is measured exactly once**, in the period its progress is reported, and that is deliberate.
     * `EarnedValue::earnedValue()` *sums* `earned_value` over every measurement up to the period, while each
     * row earns `percent_complete × the code's whole budget`. Re-measuring one code in a later period
     * therefore adds a second full claim rather than replacing the first: an earlier draft of this seeder
     * measured steel at 48% in May and 92% in June and the report came back 133% complete with a CPI of 2.98.
     * Re-measuring the *same* period updates in place, which is the supported way to correct a figure.
     *
     * So the percentage on every row below is the genuine percent complete of that code, and the totals are
     * right — at the cost of a stepped earned-value curve rather than a smooth one.
     */
    private function measureProgress(Job $job): void
    {
        $evm = app(EarnedValue::class);

        /*
         * Every percentage is derived from what physically happened above, not chosen to make the report look
         * healthy. An earlier draft claimed 92% on steel while only 40 of the 82 t ordered had been delivered,
         * and 58% on fixing against 58,061 of a 1,804,000 budget — earned value is *physical* progress, so a
         * figure that disagrees with the deliveries is simply wrong, and it inflated CPI to 1.44.
         *
         *   01.100/110  the plot is bought                              100%
         *   01.200/210  the approval is granted                         100%
         *   02.200      1,400 m³ of 1,400 excavated                     100%
         *   03.200      40 t delivered of 82 ordered                     49%
         *   03.300      12 crew-days fixed, of a 82 t package             4%
         *   03.100      300 m³ delivered of 620                          48%
         *   05.100      800 m² of 2,400 plastered                        33%
         *   08.100      six months of six supervised                    100%
         */
        $measurements = [
            0 => [['01.100', 100], ['01.110', 100]],
            1 => [['01.200', 100], ['01.210', 100]],
            2 => [['02.200', 100]],
            // Reported at the current period: work still in flight, measured once.
            5 => [['03.200', 49], ['03.300', 4], ['03.100', 48], ['05.100', 33], ['08.100', 100]],
        ];

        foreach ($measurements as $period => $rows) {
            foreach ($rows as [$code, $percent]) {
                $evm->measure($job, $this->code($code), self::PERIODS[$period], [
                    'percent_complete' => $percent,
                ]);
            }
        }
    }

    /**
     * The fourth column: what the job is now expected to cost.
     *
     * Without an issued run, `forecast_final`, `cost_to_complete` and `variance` are null on every row of
     * the report — one of the four columns simply blank, which is how the first version of this seeder
     * shipped. Remaining-budget is the method chosen because it needs no manual input and so cannot go
     * stale: cost to date plus whatever the baseline still has for the work left.
     *
     * **Concrete needs a reason, and that is the example worth having.** The baseline has 6,790,000 left for
     * ready-mix while 6,976,000 is still open on the concrete order, so the forecast says the job will spend
     * less on concrete than it has already promised a supplier. The service refuses that silently and asks
     * why — a control worth demonstrating rather than tuning the numbers to avoid. Here the answer is the
     * ordinary one: the order was placed for the whole frame at a rate that has since been renegotiated
     * down, so part of it will be released rather than spent.
     */
    private function forecast(Job $job): void
    {
        $forecasts = app(ForecastService::class);

        $run = $forecasts->prepare(
            $job,
            self::PERIODS[5],
            ForecastLine::EAC_REMAINING_BUDGET,
            belowCommitmentReasons: [
                $this->code('03.100')->getKey() => 'Order placed for the whole frame at the old rate; the balance '
                    .'will be released after the June renegotiation rather than spent.',
            ],
            name: 'Outturn at July',
        );

        $forecasts->issue($run->refresh());
    }

    private function report(Job $job): void
    {
        $ledger = app(CostLedger::class);

        $this->command?->newLine();
        $this->command?->info("  {$job->code} — {$job->name}");
        $this->command?->line('  Cost to date: '.number_format($ledger->totalFor($job), 2).' '.$job->currency_code);

        foreach ($ledger->reportFor($job) as $row) {
            $this->command?->line(sprintf(
                '    %-8s %-34s %-11s %14s',
                $row['code'],
                mb_strimwidth($row['name'], 0, 34, '…'),
                $row['cost_type'],
                number_format($row['amount'], 2),
            ));
        }

        $this->command?->newLine();
        $this->command?->line('  Construction → Job cost report → '.$job->code.', period July 2026.');
    }
}
