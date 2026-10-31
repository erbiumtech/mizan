<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 5, step 1 of docs/payslip-component-input-plan.md, made to stick.
 *
 * The eleven payslip figure columns are on their way out. Before they can go, nothing may hand the
 * calculation its typed input through a column — a payslip built in code passes its overrides to
 * Payslip::createWithInputs() or saveWithInputs(), and the suite is the place that habit would creep back
 * in first. This test reads the suite rather than running it, the way TranslationCoverageTest reads the
 * modules: a literal override key inside a Payslip::create([...]) array fails here with the file named.
 *
 * Overrides passed through a variable (a helper's array_merge) are not caught by a grep; the save hook's
 * lift (PayslipService::liftTypedInput) takes them off the row at save time, so they are safe, only
 * invisible here.
 */
class PayslipInputsOffTheColumnsTest extends TestCase
{
    private const OVERRIDE_KEYS = [
        'bonus', 'extra_work_hours', 'device_allowance', 'petrol_allowance',
        'advances', 'meal_deduction', 'esi_health_insurance', 'expense_reimbursement',
    ];

    public function test_no_payslip_is_created_in_the_suite_with_an_override_column(): void
    {
        $offenders = [];
        $keys = implode('|', self::OVERRIDE_KEYS);

        foreach ([base_path('tests'), database_path('seeders'), app_path()] as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
                    continue;
                }

                $source = $file->getContents();
                $offset = 0;

                while (($start = strpos($source, 'Payslip::create([', $offset)) !== false) {
                    $open = $start + strlen('Payslip::create(');
                    $depth = 0;
                    $end = $open;

                    for ($i = $open, $length = strlen($source); $i < $length; $i++) {
                        if ($source[$i] === '[') {
                            $depth++;
                        } elseif ($source[$i] === ']' && --$depth === 0) {
                            $end = $i;
                            break;
                        }
                    }

                    $array = substr($source, $open, $end - $open);

                    if (preg_match("/'({$keys})'\s*=>/", $array, $match)) {
                        $line = substr_count(substr($source, 0, $start), "\n") + 1;
                        $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).":{$line} passes {$match[1]} as a column";
                    }

                    $offset = $end;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'A payslip built in code hands its typed overrides to the input store, never to a figure column:',
            "Payslip::createWithInputs([...attributes], ['bonus' => 5000]). docs/payslip-component-input-plan.md Phase 5.",
            '',
            ...$offenders,
        ]));
    }
}
