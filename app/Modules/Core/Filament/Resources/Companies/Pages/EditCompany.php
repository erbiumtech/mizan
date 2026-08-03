<?php

namespace App\Modules\Core\Filament\Resources\Companies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Companies\CompanyResource;
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
        foreach ($this->licenceState as $module => $licensed) {
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
}
