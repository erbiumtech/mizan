<?php

namespace Database\Seeders;

use App\Modules\Construction\Models\CostCode;
use Illuminate\Database\Seeder;

/**
 * A starter cost-code library — `docs/construction-management-plan.md` §2.2.
 *
 * §2.2's argument for a company-wide library rather than per-job codes is that "one code means one thing
 * on every job, which is what makes the cross-job rate question answerable". Nothing seeds one today, so
 * a company arrives at the job-cost screens with an empty library — and `CostLedger::record()` needs a
 * leaf code, so **until somebody hand-builds a tree, no cost can be booked at all.** This is the shortest
 * path from a licensed module to a usable one.
 *
 * Deliberately a *starter* library and not a standard. §2.1 maps codes to MasterFormat, Uniclass, NRM and
 * ICMS, and every one of those is a published document this seeder has no business paraphrasing. So:
 *
 *  - **`icms_category` is set**, because ICMS 3 Level 2 is six letters and `CostCode::ICMS_CATEGORIES`
 *    already names them. Acquisition (`A`) against land and approvals; Construction (`C`) against work.
 *  - **`icms_group` and the four other classification columns are left null.** They are specific published
 *    values, and inventing plausible-looking ones would put wrong data behind a report meant for an
 *    external audience. The migration is explicit that this is allowed: "an unmapped code is a countable
 *    row in the report for that standard, not a blocked save".
 *
 * Headings are created before their children and `is_leaf` looks after itself — `CostCode::booted()` marks
 * a parent as a branch when a child is created, so a heading can never be booked against by accident.
 */
class ConstructionCostCodeSeeder extends Seeder
{
    /**
     * Headings, each with the leaves a job actually books against.
     *
     * `01` is the group this application could not previously express well: land, transfer duty and the
     * development-authority approvals that a developer's first year of spend consists almost entirely of.
     * They are ICMS Acquisition, and they are `other` cost type because none of labour, material, plant or
     * subcontract describes buying a plot — see the note on the enum in the class docblock of the demo
     * seeder.
     *
     * @var array<string, array{name: string, icms: string, children: array<string, array{0: string, 1: string, 2?: string}>}>
     */
    private const LIBRARY = [
        '01' => [
            'name' => 'Land acquisition and approvals',
            'icms' => 'A',
            'children' => [
                '01.100' => ['Land purchase', CostCode::TYPE_OTHER, 'sum'],
                '01.110' => ['Land transfer duty and registration', CostCode::TYPE_OTHER, 'sum'],
                '01.120' => ['Land survey and demarcation', CostCode::TYPE_OTHER, 'sum'],
                '01.200' => ['Development authority approval', CostCode::TYPE_OTHER, 'sum'],
                '01.210' => ['Building plan scrutiny fees', CostCode::TYPE_OTHER, 'sum'],
                '01.220' => ['Utility connection charges', CostCode::TYPE_OTHER, 'sum'],
                '01.300' => ['Design and professional fees', CostCode::TYPE_OTHER, 'sum'],
            ],
        ],
        '02' => [
            'name' => 'Site preparation',
            'icms' => 'C',
            'children' => [
                '02.100' => ['Site clearance', CostCode::TYPE_SUBCONTRACT, 'm2'],
                '02.200' => ['Excavation', CostCode::TYPE_PLANT, 'm3'],
                '02.300' => ['Backfill and compaction', CostCode::TYPE_SUBCONTRACT, 'm3'],
                '02.400' => ['Disposal off site', CostCode::TYPE_SUBCONTRACT, 'm3'],
            ],
        ],
        '03' => [
            'name' => 'Concrete and reinforcement',
            'icms' => 'C',
            'children' => [
                '03.100' => ['Ready-mix concrete', CostCode::TYPE_MATERIAL, 'm3'],
                '03.200' => ['Reinforcement steel', CostCode::TYPE_MATERIAL, 't'],
                '03.300' => ['Steel fixing', CostCode::TYPE_LABOUR, 't'],
                '03.400' => ['Formwork and shuttering', CostCode::TYPE_LABOUR, 'm2'],
                '03.500' => ['Concrete placing and curing', CostCode::TYPE_LABOUR, 'm3'],
            ],
        ],
        '04' => [
            'name' => 'Masonry',
            'icms' => 'C',
            'children' => [
                '04.100' => ['Blocks and bricks', CostCode::TYPE_MATERIAL, 'no'],
                '04.200' => ['Cement and sand', CostCode::TYPE_MATERIAL, 't'],
                '04.300' => ['Masonry labour', CostCode::TYPE_LABOUR, 'm2'],
            ],
        ],
        '05' => [
            'name' => 'Finishes',
            'icms' => 'C',
            'children' => [
                '05.100' => ['Plaster and render', CostCode::TYPE_SUBCONTRACT, 'm2'],
                '05.200' => ['Paint and decoration', CostCode::TYPE_SUBCONTRACT, 'm2'],
                '05.300' => ['Floor and wall tiling', CostCode::TYPE_SUBCONTRACT, 'm2'],
                '05.400' => ['Doors and windows', CostCode::TYPE_MATERIAL, 'no'],
            ],
        ],
        '06' => [
            'name' => 'Mechanical, electrical and plumbing',
            'icms' => 'C',
            'children' => [
                '06.100' => ['Electrical installation', CostCode::TYPE_SUBCONTRACT, 'sum'],
                '06.200' => ['Plumbing and drainage', CostCode::TYPE_SUBCONTRACT, 'sum'],
                '06.300' => ['HVAC installation', CostCode::TYPE_SUBCONTRACT, 'sum'],
                '06.400' => ['Lift installation', CostCode::TYPE_SUBCONTRACT, 'no'],
            ],
        ],
        '07' => [
            'name' => 'Plant and equipment',
            'icms' => 'C',
            'children' => [
                '07.100' => ['Excavator hire', CostCode::TYPE_PLANT, 'hr'],
                '07.200' => ['Tower crane hire', CostCode::TYPE_PLANT, 'month'],
                '07.300' => ['Concrete pump hire', CostCode::TYPE_PLANT, 'hr'],
                '07.400' => ['Scaffolding hire', CostCode::TYPE_PLANT, 'month'],
            ],
        ],
        '08' => [
            'name' => 'Preliminaries',
            'icms' => 'C',
            'children' => [
                '08.100' => ['Site supervision', CostCode::TYPE_LABOUR, 'hr'],
                '08.200' => ['Site accommodation and offices', CostCode::TYPE_OTHER, 'month'],
                '08.300' => ['Temporary utilities', CostCode::TYPE_OTHER, 'sum'],
                '08.400' => ['Health, safety and environment', CostCode::TYPE_OTHER, 'sum'],
                '08.500' => ['Insurances and bonds', CostCode::TYPE_OTHER, 'sum'],
            ],
        ],
    ];

    public function run(): void
    {
        $sort = 0;

        foreach (self::LIBRARY as $code => $group) {
            $heading = CostCode::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $group['name'],
                    'cost_type' => CostCode::TYPE_OTHER,
                    'icms_category' => $group['icms'],
                    'sort_order' => $sort += 10,
                ],
            );

            foreach ($group['children'] as $childCode => [$name, $type, $unit]) {
                CostCode::updateOrCreate(
                    ['code' => $childCode],
                    [
                        'parent_id' => $heading->getKey(),
                        'name' => $name,
                        'cost_type' => $type,
                        'unit' => $unit,
                        'icms_category' => $group['icms'],
                        'sort_order' => $sort += 10,
                    ],
                );
            }
        }

        $this->command?->info('  Cost codes: '.CostCode::count().' in the library, '.CostCode::query()->bookable()->count().' bookable.');
    }
}
