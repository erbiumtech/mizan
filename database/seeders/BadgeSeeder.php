<?php

namespace Database\Seeders;

use App\Modules\Performance\Models\Badge;
use Illuminate\Database\Seeder;

/**
 * The three badges every company starts with — docs/saas-launch-plan.md §8.9.
 *
 * Each carries a rule the builder could have written, so a company sees what a
 * rule looks like before writing one. Seeded once, by name: a company that
 * renamed or retired one is not given it back.
 */
class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            [
                'name' => 'Perfect Month',
                'description' => 'A month present every working day, without a late mark.',
                'icon' => 'heroicon-o-sun',
                'points' => 10,
                'rule' => ['any' => [['all' => [
                    ['field' => 'attendance.present_ratio', 'operator' => '>=', 'value' => 1, 'window' => 'last_month'],
                    ['field' => 'attendance.late_marks', 'operator' => '=', 'value' => 0, 'window' => 'last_month'],
                ]]]],
            ],
            [
                'name' => 'Five Years',
                'description' => 'Five years with the company.',
                'icon' => 'heroicon-o-trophy',
                'points' => 25,
                'rule' => ['any' => [['all' => [
                    ['field' => 'years_of_service', 'operator' => '>=', 'value' => 5],
                ]]]],
            ],
            [
                'name' => 'Reliable Reporter',
                'description' => 'Every daily report last month filed, nine in ten on the day.',
                'icon' => 'heroicon-o-document-check',
                'points' => 5,
                'rule' => ['any' => [['all' => [
                    ['field' => 'reports.filed_ratio', 'operator' => '>=', 'value' => 0.9, 'window' => 'last_month'],
                ]]]],
            ],
        ];

        foreach ($badges as $badge) {
            if (Badge::query()->where('name', $badge['name'])->exists()) {
                continue;
            }

            Badge::create($badge + ['is_active' => true]);
        }
    }
}
