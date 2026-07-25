<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = Str::slug($name).'-'.Str::lower(Str::random(4));

        return [
            'name' => $name,
            'slug' => $slug,
            'database' => database_path("tenants/{$slug}.sqlite"),
            'status' => 1,
        ];
    }
}
