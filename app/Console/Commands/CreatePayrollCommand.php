<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreatePayrollCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'open-payroll:create-payroll {month-year?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the Payroll and generates the payslips for the employees.';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Get the "month-year" argument
        $monthYear = $this->argument('month-year');

        // If month-year is not provided, default to the previous month
        if (!$monthYear) {
            $date = Carbon::now()->subMonth();
            $monthYear = $date->format('n-Y'); // Format as '9-2024'
        } else {
            // Validate the format of month-year using Laravel Validator
            $validator = Validator::make(
                ['month_year' => $monthYear],
                ['month_year' => ['required', 'date_format:n-Y']]
            );

            if ($validator->fails()) {
                $this->error('Invalid month-year format. Use m-yyyy format like 9-2024 or 09-2024.');
                return 1;
            }

            // Convert to Carbon for further processing if needed
            [$month, $year] = explode('-', $monthYear);
            $date = Carbon::createFromDate($year, $month, 1);
        }

        // Output the selected month and year
        $this->info("Generating payroll for {$date->format('F Y')}...");

        // Payroll generation logic
        $payroll = new \App\Models\OpenPayroll\Payroll();

        $payroll->user_id = 1;

        // Set the month and year separately
        $payroll->month = $date->format('n'); // 1-12 for month
        $payroll->year = $date->format('Y');

        // Set the date as the 5th of the specified month and year
        $payroll->date = $date->setDay(5); // Sets the day to the 5th

        // Save the payroll
        $payroll->save();

        $employees = \App\Models\Employee::all();
        \App\Jobs\PayslipJob::dispatch($employees, $payroll);

        $this->info("Payroll is created. Now, we are creating the payslips for each employees and calculate the base earnings and deductions.");
    }
}
