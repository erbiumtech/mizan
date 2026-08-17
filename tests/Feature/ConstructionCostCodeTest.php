<?php

namespace Tests\Feature;

use App\Modules\Construction\Filament\Resources\CostCodes\CostCodeResource;
use App\Modules\Construction\Models\CostCode;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Services\CsvImportService;
use App\Support\CsvImporters;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The cost-code library, and the CSV import that is the only way into it.
 *
 * `docs/construction-management-plan.md` §2, Phase 1c. Per tenant and shared by every job (§2.2), with the
 * standard classifications as **columns on one tree** rather than four sibling trees (§2.1) — because four
 * trees means classifying every cost four times, the four drifting, and a monthly reconciliation job.
 *
 * **Nothing proprietary is seeded**, which Phase 0 decided: MasterFormat is CSI's, Uniclass NBS's, NRM RICS's
 * and ICMS the Coalition's. So the import is not a convenience feature, it is the delivery mechanism, and it
 * gets tested like one.
 */
class ConstructionCostCodeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'costcodes@test.local'));
        $this->setCurrentTenant();

        CompanyModule::updateOrCreate(
            ['company_id' => $this->tenant->getKey(), 'module' => 'construction'],
            ['licensed' => true, 'enabled' => true],
        );
        modules()->flush();
    }

    private function code(string $code, string $name, array $attributes = []): CostCode
    {
        return CostCode::create(array_merge(['code' => $code, 'name' => $name], $attributes));
    }

    private function imports(): CsvImportService
    {
        return app(CsvImportService::class);
    }

    // ---------------------------------------------------------------- the library

    public function test_a_code_defaults_to_bookable_active_and_other(): void
    {
        $code = $this->code('03.30.00', 'Cast-in-place concrete');

        $this->assertTrue($code->is_leaf);
        $this->assertTrue($code->is_active);
        $this->assertSame(CostCode::TYPE_OTHER, $code->cost_type);
    }

    /** Company-wide unique, because the whole value of the library is one code meaning one thing everywhere. */
    public function test_a_code_is_unique_across_the_company(): void
    {
        $this->code('03.30.00', 'Concrete');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->code('03.30.00', 'Something else');
    }

    /**
     * A heading stops being bookable.
     *
     * A code carrying cost *and* children is counted twice in every rolled-up total, and nothing reports it.
     */
    public function test_a_code_with_children_stops_being_bookable(): void
    {
        $heading = $this->code('03', 'Concrete');

        $this->assertTrue($heading->fresh()->is_leaf);

        $this->code('03.30.00', 'Cast-in-place', ['parent_id' => $heading->getKey()]);

        $this->assertFalse($heading->fresh()->is_leaf);
    }

    public function test_bookable_excludes_headings_and_switched_off_codes(): void
    {
        $heading = $this->code('03', 'Concrete');
        $this->code('03.30.00', 'Cast-in-place', ['parent_id' => $heading->getKey()]);
        $this->code('03.99.00', 'One-off provisional', ['is_active' => false]);
        $this->code('04.00.00', 'Masonry');

        $this->assertSame(
            ['03.30.00', '04.00.00'],
            CostCode::query()->bookable()->orderBy('code')->pluck('code')->all(),
        );
    }

    /** The library is company-wide, so its subtree is not partitioned the way a job's WBS is. */
    public function test_the_library_subtree_is_not_partitioned(): void
    {
        $this->assertNull(CostCode::pathScopeColumn());

        $heading = $this->code('03', 'Concrete');
        $child = $this->code('03.30.00', 'Cast-in-place', ['parent_id' => $heading->getKey()]);

        $this->assertSame(
            ['03', '03.30.00'],
            CostCode::query()->inSubtree($heading->fresh())->orderBy('code')->pluck('code')->all(),
        );
        $this->assertSame(1, $child->fresh()->depth());
    }

    // ---------------------------------------------------------------- mapping

    /**
     * An unmapped code is findable, which is §2.1's promise.
     *
     * The alternative — dropping it from the report for that standard — produces a total that is quietly short
     * by however much was unmapped, which is the failure the whole one-tree-many-mappings design avoids.
     */
    public function test_unmapped_codes_are_findable_per_standard(): void
    {
        $this->code('03.30.00', 'Cast-in-place', ['icms_category' => 'C', 'masterformat_code' => '03 30 00']);
        $this->code('04.00.00', 'Masonry', ['icms_category' => 'C']);
        $this->code('05.00.00', 'Metals');

        $this->assertSame(
            ['04.00.00', '05.00.00'],
            CostCode::query()->unmappedFor('masterformat_code')->orderBy('code')->pluck('code')->all(),
        );

        $this->assertSame(
            ['05.00.00'],
            CostCode::query()->unmappedFor('icms_category')->orderBy('code')->pluck('code')->all(),
        );
    }

    /** An empty string counts as unmapped, because a spreadsheet import produces those rather than nulls. */
    public function test_an_empty_mapping_counts_as_unmapped(): void
    {
        $this->code('05.00.00', 'Metals', ['nrm_code' => '']);

        $this->assertSame(['05.00.00'], CostCode::query()->unmappedFor('nrm_code')->pluck('code')->all());
    }

    public function test_the_icms_category_reads_as_its_name(): void
    {
        $code = $this->code('03.30.00', 'Cast-in-place', ['icms_category' => 'C']);

        $this->assertSame('Construction', $code->icmsCategoryName());
        $this->assertNull($this->code('04.00.00', 'Masonry')->icmsCategoryName());
    }

    /**
     * Completeness is measured against the standards a company actually uses.
     *
     * A UK contractor maps NRM and Uniclass and will never fill in MasterFormat, so a figure that counted the
     * ones it does not use would read as permanently broken and get ignored.
     */
    public function test_mapping_completeness_is_measured_against_chosen_standards(): void
    {
        $code = $this->code('03.30.00', 'Cast-in-place', [
            'nrm_code' => '2.3.1',
            'uniclass_code' => 'Ss_20_10_30',
        ]);

        $this->assertTrue($code->isMappedFor(['nrm_code', 'uniclass_code']));
        $this->assertFalse($code->isMappedFor(['nrm_code', 'masterformat_code']));
    }

    // ---------------------------------------------------------------- the import

    public function test_the_importer_is_offered_on_the_import_screen(): void
    {
        $this->assertTrue(CsvImporters::has('construction_cost_codes'));
        $this->assertSame('Construction cost codes', CsvImporters::labels()['construction_cost_codes']);
    }

    /** The shipped template has to be a file this importer accepts, or it is worse than no template. */
    public function test_the_template_imports_cleanly(): void
    {
        $template = $this->imports()->template('construction_cost_codes');

        $this->assertSame(0, $this->imports()->preview($template, 'construction_cost_codes')['skipped']);
    }

    public function test_a_code_and_its_mappings_import(): void
    {
        $csv = "code,name,cost_type,unit,icms_category,icms_group,nrm_code\n"
            ."03.30.00,Cast-in-place concrete,material,m3,C,Substructure,2.3.1\n";

        $result = $this->imports()->import($csv, 'construction_cost_codes');

        $this->assertSame(1, $result['imported']);

        $code = CostCode::query()->where('code', '03.30.00')->firstOrFail();
        $this->assertSame('material', $code->cost_type);
        $this->assertSame('m3', $code->unit);
        $this->assertSame('C', $code->icms_category);
        $this->assertSame('2.3.1', $code->nrm_code);
    }

    /**
     * The parent is named by code, and a child may appear above its parent in the file.
     *
     * A spreadsheet has no database ids, and rows come out of one in whatever order the exporter chose — so
     * requiring parents first would reject most real files.
     */
    public function test_a_child_listed_before_its_parent_still_lands_under_it(): void
    {
        $csv = "code,name,parent_code\n"
            ."03.30.00,Cast-in-place,03\n"
            ."03,Concrete,\n";

        $this->imports()->import($csv, 'construction_cost_codes');

        $child = CostCode::query()->where('code', '03.30.00')->firstOrFail();
        $parent = CostCode::query()->where('code', '03')->firstOrFail();

        $this->assertSame($parent->getKey(), $child->parent_id);
        $this->assertSame("/{$parent->getKey()}/{$child->getKey()}/", $child->fresh()->path);
        $this->assertFalse($parent->fresh()->is_leaf);
    }

    /** Three levels, pathed correctly, which is what a rolled-up cost report reads. */
    public function test_a_three_level_import_paths_correctly(): void
    {
        $csv = "code,name,parent_code\n"
            ."03,Concrete,\n"
            ."03.30,Cast-in-place,03\n"
            ."03.30.10,Slabs,03.30\n";

        $this->imports()->import($csv, 'construction_cost_codes');

        $slabs = CostCode::query()->where('code', '03.30.10')->firstOrFail();

        $this->assertSame(2, $slabs->depth(), 'a third-level code is not two deep, so its rollup is wrong');
    }

    /**
     * A parent named but absent leaves the code as a root rather than refusing the file.
     *
     * One code at the wrong level is a two-second fix; a rejected import of four hundred is a morning.
     */
    public function test_a_missing_parent_leaves_the_code_at_the_top(): void
    {
        $csv = "code,name,parent_code\n03.30.00,Cast-in-place,99\n";

        $result = $this->imports()->import($csv, 'construction_cost_codes');

        $this->assertSame(1, $result['imported']);
        $this->assertNull(CostCode::query()->where('code', '03.30.00')->firstOrFail()->parent_id);
    }

    public function test_a_row_with_no_code_is_named_and_skipped(): void
    {
        $csv = "code,name\n,Nameless\n04.00.00,Masonry\n";

        $result = $this->imports()->import($csv, 'construction_cost_codes');

        $this->assertSame(1, $result['imported']);
        $this->assertStringContainsString('no code', $result['skipped'][0]);
    }

    /** A bad ICMS letter produces a report category nobody recognises, so it is refused at the row. */
    public function test_a_bad_icms_category_is_refused_with_the_valid_ones_named(): void
    {
        $csv = "code,name,icms_category\n03.30.00,Cast-in-place,Z\n";

        $result = $this->imports()->import($csv, 'construction_cost_codes');

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('not an ICMS category', $result['skipped'][0]);
        $this->assertStringContainsString('A, C, R, O, M, E', $result['skipped'][0]);
    }

    public function test_a_bad_cost_type_is_refused_with_the_valid_ones_named(): void
    {
        $csv = "code,name,cost_type\n03.30.00,Cast-in-place,concrete\n";

        $result = $this->imports()->import($csv, 'construction_cost_codes');

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('is not a cost type', $result['skipped'][0]);
    }

    /** Re-importing corrects rather than duplicates: a company fixes three rows and re-uploads the file. */
    public function test_importing_the_same_file_twice_corrects_rather_than_duplicates(): void
    {
        $csv = "code,name,unit\n03.30.00,Cast-in-place,m3\n";
        $this->imports()->import($csv, 'construction_cost_codes');

        $corrected = "code,name,unit\n03.30.00,Cast-in-place concrete,m2\n";
        $this->imports()->import($corrected, 'construction_cost_codes');

        $this->assertSame(1, CostCode::query()->count());
        $this->assertSame('m2', CostCode::query()->firstOrFail()->unit);
    }

    // ---------------------------------------------------------------- the screens

    public function test_the_list_renders_and_shows_the_tree(): void
    {
        $heading = $this->code('03', 'Concrete');
        $this->code('03.30.00', 'Cast-in-place', ['parent_id' => $heading->getKey()]);

        Livewire::test(CostCodeResource::getPages()['index']->getPage())
            ->assertSuccessful()
            ->assertSee('Cast-in-place');
    }

    public function test_the_list_defaults_to_active_codes(): void
    {
        $this->code('03.30.00', 'Cast-in-place');
        $this->code('03.99.00', 'Retired item', ['is_active' => false]);

        Livewire::test(CostCodeResource::getPages()['index']->getPage())
            ->assertSee('Cast-in-place')
            ->assertDontSee('Retired item');
    }
}
