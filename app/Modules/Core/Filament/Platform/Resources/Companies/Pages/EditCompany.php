<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Platform\Resources\Companies\CompanyResource;
use App\Modules\Core\Models\CompanyModule;
use App\Support\Modules;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Licence state lives in company_modules, not on the company row, so it is
     * loaded into the form by hand.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $state = modules()->stateFor($this->record->getKey());

        foreach (Modules::names() as $module) {
            if (Modules::isLocked($module)) {
                continue;
            }

            $data['modules'][$module] = (bool) ($state[$module]['licensed'] ?? false);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Stashed rather than saved here: the company row is written first, and
        // `modules` is not one of its columns.
        $this->licenceState = (array) ($data['modules'] ?? []);

        unset($data['modules']);

        return $data;
    }

    /** @var array<string, bool> */
    private array $licenceState = [];

    protected function afterSave(): void
    {
        foreach ($this->cascade($this->licenceState) as $module => $licensed) {
            if (! in_array($module, Modules::names(), true) || Modules::isLocked($module)) {
                continue;
            }

            $row = CompanyModule::firstOrNew([
                'company_id' => $this->record->getKey(),
                'module' => $module,
            ]);

            $wasLicensed = (bool) $row->licensed;
            $row->licensed = (bool) $licensed;

            // `enabled` is never written here. A grant with no choice recorded
            // (NULL) already reads as on, and an explicit false is the company's
            // own decision — which is exactly what has to survive a revoke so a
            // re-grant restores what they had.
            $row->save();

            if ($wasLicensed !== (bool) $licensed) {
                activity()
                    ->performedOn($this->record)
                    ->withProperties(['module' => $module, 'licensed' => (bool) $licensed])
                    ->log(($licensed ? 'Granted' : 'Revoked').' the '.Modules::label($module).' module licence');
            }
        }

        modules()->flush();
    }

    /**
     * A licence set that makes sense: nothing granted without its requirements, nothing
     * left granted whose requirements were taken away.
     *
     * Both directions matter. Granting Invoicing without Accounting sells something that
     * cannot post a single invoice, and revoking Accounting while Invoicing stays granted
     * leaves the company paying for a module that fails on first use.
     *
     * Where the two collide — Accounting switched off while Invoicing is on — the toggle
     * just moved wins, and Invoicing goes with it. Inferring the opposite would mean a
     * licence explicitly revoked came back because something else needed it, which is the
     * one outcome nobody expects from turning a toggle off. A module that has *never* been
     * granted is not a decision to withhold it, so it is still pulled in when something
     * needs it — otherwise granting Payroll would fail for want of Employees.
     *
     * @param  array<string, bool>  $desired
     * @return array<string, bool>
     */
    private function cascade(array $desired): array
    {
        $current = modules()->stateFor($this->record->getKey());
        $licences = [];
        $switchedOff = [];

        foreach (Modules::names() as $module) {
            if (Modules::isLocked($module)) {
                continue;
            }

            $licences[$module] = (bool) ($desired[$module] ?? false);

            if (! $licences[$module] && ($current[$module]['licensed'] ?? false)) {
                $switchedOff[] = $module;
            }
        }

        foreach (array_keys($licences) as $module) {
            if (! $licences[$module]) {
                continue;
            }

            foreach ($this->allRequirements($module) as $required) {
                if (Modules::isLocked($required) || in_array($required, $switchedOff, true)) {
                    continue;
                }

                $licences[$required] = true;
            }
        }

        // A fixpoint, because revoking Accounting takes Invoicing, and Invoicing takes
        // Billing.
        for ($pass = 0; $pass <= count($licences); $pass++) {
            $changed = false;

            foreach (array_keys($licences) as $module) {
                if (! $licences[$module]) {
                    continue;
                }

                foreach (Modules::requirements($module) as $required) {
                    if (Modules::isLocked($required) || ($licences[$required] ?? false)) {
                        continue;
                    }

                    $licences[$module] = false;
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $licences;
    }

    /** @return array<int, string> */
    private function allRequirements(string $module): array
    {
        $required = [];

        foreach (Modules::requirements($module) as $direct) {
            $required[] = $direct;
            $required = array_merge($required, $this->allRequirements($direct));
        }

        return array_values(array_unique($required));
    }
}
