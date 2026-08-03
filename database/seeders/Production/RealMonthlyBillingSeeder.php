<?php

namespace Database\Seeders\Production;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Advances\Models\Advance;
use App\Modules\Billing\Models\BillingRun;
use App\Modules\Billing\Services\MonthlyBillingService;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\PayrollMonth;
use App\Support\ModuleMap;
use Illuminate\Database\Seeder;

/**
 * REAL PRODUCTION DATA — kept out of the default `db:seed` run.
 *
 * One month of the "Salaries Calculation" spreadsheet, loaded into the system
 * that replaces it: each person's July package, the two staff advances and what
 * they have repaid, the month's office expenses, and the resulting bill to the
 * client.
 *
 *     php artisan tenants:artisan 'db:seed --class=Database\Seeders\Production\RealMonthlyBillingSeeder' --tenant=2
 *
 * Tenant-scoped: a company must be current. Name the company with `--tenant`;
 * without it, tenants:artisan runs this for every company, which will load the
 * real roster's figures into a demo tenant too.
 *
 * Run RealEmployeeSeeder first — the packages below are matched to people by
 * email, and anyone missing is reported rather than invented.
 *
 * Idempotent: re-running updates the same rows and rebuilds the same draft
 * invoice rather than raising a second one.
 */
class RealMonthlyBillingSeeder extends Seeder
{
    /**
     * The payroll month this loads, within the company's *active* fiscal year —
     * so on a company whose active year is 2026-2027 this is July 2026, and the
     * dates follow from that rather than being pinned here.
     */
    private const MONTH = 'July';

    /**
     * PKR per 1 EUR, from the rate row on the sheet. What the client is quoted
     * at; the books stay in PKR.
     */
    private const EXCHANGE_RATE = 304;

    /**
     * The rate the EUR part of a package is converted at.
     *
     * Deliberately its own constant even though it equals the quote rate today:
     * the client's rate is renegotiated per month, and moving it should not
     * silently rewrite what somebody was paid.
     */
    private const EUR_SALARY_RATE = 304;

    private const CLIENT = '4sure AG';

    /**
     * Each person's July package, keyed by the email RealEmployeeSeeder gives
     * them: [basic, extra work, petrol, medical, device].
     *
     * The PKR figures add up to the sheet's own salary total exactly: 3,961,427.
     * On top of that sits the EUR component the sheet lists separately under
     * "Muzafar Ali" — the same person as the ufarooq@erbium.ch account below —
     * held converted, since the company keeps one currency. See EUR_SALARY_RATE.
     */
    private const PACKAGES = [
        'harshad@erbium.ch' => [416745, 0, 20000, 21000, 0],
        'awahab@erbium.ch' => [400680, 4452, 20000, 21000, 0],
        'rbukhari@erbium.ch' => [409500, 0, 20000, 21000, 10000],
        'mbakar@erbium.ch' => [396900, 0, 20000, 21000, 0],
        'nahmad@erbium.ch' => [381150, 0, 20000, 21000, 0],
        'mmujahid@erbium.ch' => [379500, 0, 20000, 21000, 0],
        'nyahya@erbium.ch' => [180000, 0, 20000, 21000, 0],
        'hjaved@erbium.ch' => [180000, 0, 20000, 21000, 0],
        // One person with two rows on the sheet — a PKR salary and a EUR component
        // of 2,600 + 300 + 200 + 150 + 50, so 3,300 a month, listed there under
        // "Muzafar Ali". The sheet bills both and an employee has one package, so
        // they are added together, written as the arithmetic rather than the answer.
        'ufarooq@erbium.ch' => [
            472500 + 2600 * self::EUR_SALARY_RATE,
            0 + 300 * self::EUR_SALARY_RATE,
            20000 + 200 * self::EUR_SALARY_RATE,
            21000 + 150 * self::EUR_SALARY_RATE,
            0 + 50 * self::EUR_SALARY_RATE,
        ],
        'arooj.fatima@erbium.ch' => [45000, 0, 20000, 0, 0],

        // Support staff with no company mailbox — see the note on their
        // placeholder addresses in RealEmployeeSeeder.
        'muhammad.abid@example.test' => [200000, 0, 20000, 21000, 0],
        'ahmad.ishtiaq@example.test' => [35000, 0, 20000, 0, 0],
    ];

    /**
     * The loan block on the sheet: what was lent, and what comes off each month.
     *
     * Only these two are stated — what has been recovered and what is left are
     * not seeded, because payroll derives them from the payslips below.
     */
    private const ADVANCES = [
        'mmujahid@erbium.ch' => [1500000, 60000],
        'harshad@erbium.ch' => [1500000, 70000],
    ];

    /**
     * The expenses block: [description, transaction type code, amount, day of month].
     *
     * The sheet carries no dates, so these are the days each cost is actually
     * incurred — rent at the start of the month, the food bill at the end.
     */
    private const EXPENSES = [
        ['House rent', 'rent', 92000, 1],
        ['Electricity, water and society charges', 'utilities', 35846, 10],
        ['Gas', 'utilities', 980, 10],
        ['Cleaning', 'cleaning', 20000, 5],
        ['Internet', 'utilities', 25000, 5],
        ['Monthly food', 'food', 330000, 31],
        ['Accountant', 'miscellaneous', 10000, 28],
        ['New connection monthly billing', 'utilities', 130000, 15],
        ['Electricity — office', 'utilities', 45000, 10],
        ['Paddle court', 'miscellaneous', 45000, 20],
        ['Dinner', 'food', 50000, 25],
        ['AC gas and kitchen exhaust', 'equipment', 25000, 18],

        // The advance paid out to Hammad Arshad. It left the company's bank in
        // July like any other cost and is billed as one; the repayments then come
        // off the bill month by month as the advance above is recovered.
        ['Advance to Hammad Arshad', 'miscellaneous', 1500000, 3],
    ];

    public function run(): void
    {
        // Whose modules to license. tenants:artisan makes the company current;
        // falling back to the first one covers a context that has the tenant
        // connection configured without going through tenancy, which is how the
        // test suite runs it.
        $company = Company::current() ?? Company::query()->first();

        if (! $company) {
            $this->command?->error('No company found. Run CompanySeeder first, or run this via tenants:artisan.');

            return;
        }

        $fiscalYear = FiscalYear::where('is_active', true)->first()
            ?? FiscalYear::where('name', '2026-2027')->first();

        if (! $fiscalYear) {
            $this->command?->warn('No fiscal year found; run FiscalYearSeeder first.');

            return;
        }

        $this->enableModules($company);

        $employees = $this->packages($fiscalYear);

        if ($employees->isEmpty()) {
            $this->command?->warn('None of the roster was found; run RealEmployeeSeeder first.');

            return;
        }

        $this->advances($employees, $fiscalYear);

        // After the advances, so the first payslip already carries the instalment.
        $this->payslips($employees, $fiscalYear);

        $this->expenses($fiscalYear);
        $this->bill($fiscalYear);
    }

    /**
     * Advances and Billing have to be licensed and on for this company, or the
     * data comes out quietly wrong rather than missing: payslips would carry no
     * instalment and the bill would credit nothing back.
     */
    private function enableModules(Company $company): void
    {
        foreach (['employees', 'payroll', 'accounting', 'invoicing', 'advances', 'billing'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();

        $this->command?->info('Licensed and enabled: employees, payroll, accounting, invoicing, advances, billing.');
    }

    /**
     * @return \Illuminate\Support\Collection<string, Employee> keyed by email
     */
    private function packages(FiscalYear $fiscalYear): \Illuminate\Support\Collection
    {
        $found = collect();
        $missing = [];

        // `users` is the one shared landlord table, so it cannot be reached from a
        // query on the tenant connection. Resolve the accounts first, then match
        // the tenant's employees by id.
        $userIds = User::query()->acrossCompanies()
            ->whereIn('email', array_keys(self::PACKAGES))
            ->pluck('id', 'email');

        foreach (self::PACKAGES as $email => [$basic, $extra, $petrol, $medical, $device]) {
            $employee = isset($userIds[$email])
                ? Employee::where('user_id', $userIds[$email])->first()
                : null;

            if (! $employee) {
                $missing[] = $email;

                continue;
            }

            EmployeeSetting::updateOrCreate(
                ['employee_id' => $employee->id, 'fiscal_year_id' => $fiscalYear->id],
                [
                    'start_date' => $fiscalYear->start_date?->toDateString() ?? '2026-07-01',
                    'end_date' => $fiscalYear->end_date?->toDateString() ?? '2027-06-30',
                    'basic_wage' => $basic,
                    'extra_work_hours' => $extra,
                    'petrol_allowance' => $petrol,
                    'medical_allowance' => $medical,
                    'device_allowance' => $device,

                    // Nothing typed in: the deduction now comes from the advance
                    // ledger, and a figure here would override it.
                    'advances' => 0,
                    'meal_deduction' => 0,
                    'esi_health_insurance' => 0,
                ]
            );

            $found[$email] = $employee;
        }

        $this->command?->info("Seeded July packages for {$found->count()} employees.");

        if ($missing) {
            $this->command?->warn('Not in the roster, so not seeded: '.implode(', ', $missing));
        }

        return $found;
    }

    private function advances(\Illuminate\Support\Collection $employees, FiscalYear $fiscalYear): void
    {
        // Dated to the month being loaded, not a fixed year: an advance that
        // starts after the payslip that recovers from it reads as nonsense on its
        // own history.
        $startedOn = PayrollMonth::firstDay(self::MONTH, $fiscalYear);

        foreach (self::ADVANCES as $email => [$total, $instalment]) {
            $employee = $employees[$email] ?? null;

            if (! $employee) {
                continue;
            }

            // Keyed on the person and the sheet alone, with no month in it: the
            // loan is one ongoing debt, so loading a second month has to recover
            // from it again rather than open a second one.
            $advance = Advance::firstOrNew([
                'employee_id' => $employee->id,
                'reference' => 'SHEET',
            ]);

            $advance->fill([
                'total_amount' => $total,
                'monthly_instalment' => $instalment,
                'notes' => 'Carried over from the salaries spreadsheet.',
            ]);

            // Only on the way in. Re-running for a later month must not move the
            // start date forward, and must not reopen a loan since settled.
            if (! $advance->exists) {
                $advance->started_on = $startedOn->toDateString();
                $advance->status = Advance::STATUS_ACTIVE;
            }

            $advance->save();
        }

        $this->command?->info('Seeded '.count(self::ADVANCES).' staff advances.');
    }

    /**
     * Only the inputs are set. Payslip::booted() runs the payroll calculation,
     * the tax sync, the journal posting and the advance recovery, so the figures
     * are produced exactly as they are in the application.
     */
    private function payslips(\Illuminate\Support\Collection $employees, FiscalYear $fiscalYear): void
    {
        foreach ($employees as $employee) {
            $payslip = Payslip::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'month' => self::MONTH,
                    'fiscal_year_id' => $fiscalYear->id,
                ],
                [
                    'total_working_days' => 22,
                    'paid_days' => 22,
                    'lop_days' => 0,
                    'leaves_taken' => 0,
                ]
            );

            if ($payslip->wasRecentlyCreated) {
                continue;
            }

            // An existing payslip needs two nudges to follow a revised package.
            //
            // A figure already on a payslip is treated by payroll as a deliberate
            // override — one month's petrol corrected by hand stays corrected — so
            // it would win over the settings written above. The sheet is what this
            // seeder loads, so the overrides are cleared and the settings drive the
            // figures again. Anything typed on top of a payslip since the last run
            // goes with them, which is the point of re-running it.
            $payslip->forceFill([
                'petrol_allowance' => 0,
                'device_allowance' => 0,
                'bonus' => 0,
                'extra_work_hours' => 0,
                'meal_deduction' => 0,
                'esi_health_insurance' => 0,

                // Zero so the advance ledger sets it, rather than the figure the
                // last run left behind.
                'advances' => 0,
            ]);

            // And a payslip that ends up matching what is already stored is not
            // dirty, so Eloquent would run no UPDATE and the recalculation hooked to
            // `updating` would never fire. touch() makes the save real.
            $payslip->touch();
        }

        $this->command?->info('Seeded '.self::MONTH.' payslips for '.$employees->count().' employees.');
    }

    private function expenses(FiscalYear $fiscalYear): void
    {
        $month = PayrollMonth::firstDay(self::MONTH, $fiscalYear);

        foreach (self::EXPENSES as [$details, $code, $amount, $day]) {
            $type = TransactionType::byCode($code);

            if (! $type) {
                $this->command?->warn("No '{$code}' transaction type; run TransactionTypeSeeder first. Skipped: {$details}");

                continue;
            }

            $payee = $this->payeeFor($type);
            $valueDate = $month->copy()->day(min($day, $month->daysInMonth));

            Payment::updateOrCreate(
                [
                    'details' => $details.' — '.$month->format('F Y'),
                    'transaction_type_id' => $type->id,
                ],
                [
                    'payable_type' => ModuleMap::alias(Beneficiary::class),
                    'payable_id' => $payee->id,
                    'amount' => $amount,
                    'value_date' => $valueDate->toDateString(),
                    'status' => Payment::STATUS_APPROVED,
                ]
            );
        }

        $this->command?->info('Seeded '.count(self::EXPENSES).' office expenses for '.$month->format('F Y').'.');
    }

    /**
     * The beneficiary set up for this kind of payment where there is one — the
     * office owner for rent, the caterer for food — and a sundry payee for the
     * rest, rather than inventing a payee per line.
     */
    private function payeeFor(TransactionType $type): Beneficiary
    {
        return Beneficiary::where('transaction_type_id', $type->id)->where('is_active', true)->first()
            ?? Beneficiary::firstOrCreate(
                ['name' => 'Sundry office payee'],
                ['is_active' => true, 'transaction_type_id' => $type->id],
            );
    }

    private function bill(FiscalYear $fiscalYear): void
    {
        $client = Contact::firstOrCreate(
            ['name' => self::CLIENT],
            ['kind' => Contact::KIND_CUSTOMER, 'is_active' => true],
        );

        $month = PayrollMonth::firstDay(self::MONTH, $fiscalYear);

        $run = BillingRun::updateOrCreate(
            [
                'contact_id' => $client->id,
                'month' => self::MONTH,
                'fiscal_year_id' => $fiscalYear->id,
            ],
            [
                // Billed once the month is complete.
                'invoice_date' => $month->copy()->endOfMonth()->addDay()->toDateString(),
                'due_date' => $month->copy()->endOfMonth()->addDays(15)->toDateString(),
                'currency' => 'EUR',
                'exchange_rate' => self::EXCHANGE_RATE,
                'notes' => 'Loaded from the salaries spreadsheet.',
            ]
        );

        $billing = app(MonthlyBillingService::class);
        $breakdown = $billing->breakdown($run);

        if (! $run->isRebuildable()) {
            $this->command?->warn(
                "{$run->invoice->invoice_number} has already been issued, so it was left alone. "
                .'The figures below are what a fresh bill for this month would contain.'
            );
        } else {
            $invoice = $billing->build($run);
            $this->command?->info("Built {$invoice->invoice_number} as a draft: review it under Invoices before issuing.");
        }

        $this->report($run->fresh(), $breakdown);
    }

    /**
     * Print the month the way the sheet totalled it, so the two can be compared
     * at a glance.
     */
    private function report(BillingRun $run, array $breakdown): void
    {
        $money = fn (float $amount): string => number_format($amount, 2);

        $this->command?->newLine();
        $this->command?->info("Bill to ".self::CLIENT." for {$run->periodLabel()}");
        $this->command?->table(
            ['', 'PKR'],
            [
                ['Salaries ('.count($breakdown['salaries']).' employees)', $money($breakdown['salary_total'])],
                ['Office expenses ('.count($breakdown['expenses']).' lines)', $money($breakdown['expense_total'])],
                ['Less advance repayments', $money($breakdown['credit_total'])],
                ['Total', $money($breakdown['subtotal'])],
                [
                    'Quoted at '.number_format(self::EXCHANGE_RATE).' per EUR',
                    'EUR '.$money($breakdown['subtotal'] / self::EXCHANGE_RATE),
                ],
            ]
        );
    }
}
