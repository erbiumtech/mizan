<?php

namespace Database\Factories;

use App\Models\Payroll\Payroll;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payroll\Payroll>
 */
class OpenPayrollFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Payroll\Payroll>
     */
    protected $model = Payroll::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = (int) fake()->year();
        $month = fake()->numberBetween(1, 12);

        return [
            'user_id' => User::factory(),
            'month' => $month,
            'year' => $year,
            'date' => Carbon::create($year, $month, fake()->numberBetween(1, 28))->toDateString(),
            'is_locked' => false,
        ];
    }
}
