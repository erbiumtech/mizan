<?php

namespace Database\Factories;

use App\Models\TableView;
use Illuminate\Database\Eloquent\Factories\Factory;

class TableViewFactory extends Factory
{
    protected $model = TableView::class;

    public function definition(): array
    {
        return [
            'company_id' => 1,
            'user_id' => 1,
            'resource' => 'app.filament.resources.payslips.pages.list-payslips',
            'name' => $this->faker->words(2, true),
            'icon' => null,
            'color' => null,
            'is_favorite' => false,
            'is_public' => false,
            'is_global' => false,
            'is_default' => false,
            'state' => ['filters' => [], 'columns' => [], 'search' => null, 'sort' => null],
            'sort' => 0,
        ];
    }

    public function favorite(): static
    {
        return $this->state(['is_favorite' => true]);
    }

    public function public(): static
    {
        return $this->state(['is_public' => true]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
