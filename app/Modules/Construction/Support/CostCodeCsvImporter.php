<?php

namespace App\Modules\Construction\Support;

use App\Modules\Construction\Models\CostCode;
use App\Support\Contracts\CsvImporter;
use Illuminate\Support\Collection;

/**
 * A company's cost-code library, from a spreadsheet.
 *
 * **This is how codes arrive, and that is a decision rather than a convenience.** Phase 0 of
 * `docs/construction-management-plan.md` settled that no proprietary code list ships: MasterFormat is CSI's,
 * Uniclass NBS's, NRM RICS's and ICMS the Coalition's, and redistribution terms cannot be cleared from inside
 * the codebase. So the structure ships and the customer loads their own file — which also disposes of the
 * ten-thousand-rows-per-tenant objection §18.2 raises, by not creating them.
 *
 * Registered through `App\Support\CsvImporters`, so it appears on Core's existing import screen with the
 * contacts and products importers rather than needing a screen of its own.
 *
 * **The parent is named by code, not by id.** A spreadsheet has no database ids, and asking somebody to fill
 * in a `parent_id` column is asking them to guess. So `parent_code` refers to another row by its own code, and
 * rows are sorted by depth before writing so a parent always exists before its children — which is what lets
 * one file describe a whole tree in any order.
 */
class CostCodeCsvImporter implements CsvImporter
{
    public function key(): string
    {
        return 'construction_cost_codes';
    }

    public function label(): string
    {
        return 'Construction cost codes';
    }

    public function columns(): array
    {
        return [
            'code', 'name', 'parent_code', 'cost_type', 'unit',
            'masterformat_code', 'uniformat_code', 'uniclass_code', 'omniclass_code', 'nrm_code',
            'icms_category', 'icms_group',
        ];
    }

    public function example(): array
    {
        return [
            '03.30.00', 'Cast-in-place concrete', '03', 'material', 'm3',
            '03 30 00', 'B1010', 'Ss_20_10_30', '23-13 00 00', '2.3.1',
            'C', 'Substructure',
        ];
    }

    public function dateField(): ?array
    {
        return null;
    }

    public function problemWith(array $row): ?string
    {
        if ($row['code'] === '') {
            return 'no code';
        }

        if ($row['name'] === '') {
            return 'no name';
        }

        if ($row['cost_type'] !== '' && ! in_array(strtolower($row['cost_type']), $this->costTypes(), true)) {
            return sprintf(
                '"%s" is not a cost type — use one of %s',
                $row['cost_type'],
                implode(', ', $this->costTypes()),
            );
        }

        // A single letter, and one of the six ICMS 3 categories. A typo here does not fail the row loudly
        // later — it produces a report with a category nobody recognises.
        if ($row['icms_category'] !== '' && ! array_key_exists(strtoupper($row['icms_category']), CostCode::ICMS_CATEGORIES)) {
            return sprintf(
                '"%s" is not an ICMS category — use one of %s',
                $row['icms_category'],
                implode(', ', array_keys(CostCode::ICMS_CATEGORIES)),
            );
        }

        return null;
    }

    /**
     * Write the codes, parents before children.
     *
     * Two passes rather than one recursive walk: the first creates or updates every row without a parent link,
     * the second resolves `parent_code` to an id now that every code exists. That way a file listing a child
     * above its parent still imports, and a `parent_code` naming a row that is not in the file *and* not
     * already in the library leaves the code as a root rather than failing the whole import — a library with
     * one code at the wrong level is fixable, and a refused import of four hundred is a morning gone.
     */
    public function write(Collection $rows, ?string $date = null): int
    {
        $written = 0;

        foreach ($rows as $row) {
            CostCode::updateOrCreate(
                ['code' => trim($row['code'])],
                [
                    'name' => $row['name'],
                    'cost_type' => $row['cost_type'] !== '' ? strtolower($row['cost_type']) : CostCode::TYPE_OTHER,
                    'unit' => $row['unit'] ?: null,
                    'masterformat_code' => $row['masterformat_code'] ?: null,
                    'uniformat_code' => $row['uniformat_code'] ?: null,
                    'uniclass_code' => $row['uniclass_code'] ?: null,
                    'omniclass_code' => $row['omniclass_code'] ?: null,
                    'nrm_code' => $row['nrm_code'] ?: null,
                    'icms_category' => $row['icms_category'] !== '' ? strtoupper($row['icms_category']) : null,
                    'icms_group' => $row['icms_group'] ?: null,
                ],
            );

            $written++;
        }

        $this->linkParents($rows);

        return $written;
    }

    /**
     * Resolve `parent_code` to a parent id, shallowest first.
     *
     * Shallowest first so `HasMaterialisedPath` builds each path from a parent whose own path is already
     * written — otherwise a grandchild imported before its parent was linked would path through a parent that
     * had none, and its rollup would be wrong from the start. Depth is approximated by how many separators the
     * code carries, which is how every one of these schemes numbers.
     *
     * @param  Collection<int, array<string, string>>  $rows
     */
    private function linkParents(Collection $rows): void
    {
        $withParents = $rows
            ->filter(fn (array $row): bool => trim($row['parent_code'] ?? '') !== '')
            ->sortBy(fn (array $row): int => substr_count(trim($row['code']), '.') + substr_count(trim($row['code']), ' '))
            ->values();

        foreach ($withParents as $row) {
            $code = CostCode::query()->where('code', trim($row['code']))->first();
            $parent = CostCode::query()->where('code', trim($row['parent_code']))->first();

            // A parent named but not present is left as a root rather than refusing the file. One code at the
            // wrong level is a two-second fix; a rejected import of four hundred is a morning.
            if (! $code || ! $parent || $parent->getKey() === $code->getKey()) {
                continue;
            }

            $code->update(['parent_id' => $parent->getKey()]);
        }
    }

    /** @return array<int, string> */
    private function costTypes(): array
    {
        return [
            CostCode::TYPE_LABOUR,
            CostCode::TYPE_MATERIAL,
            CostCode::TYPE_PLANT,
            CostCode::TYPE_SUBCONTRACT,
            CostCode::TYPE_OTHER,
        ];
    }
}
