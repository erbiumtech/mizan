<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Platform\Resources\Companies\CompanyResource;
use App\Modules\Core\Models\CompanyModule;
use App\Support\CompanyProfiles;
use App\Support\Modules;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditCompany extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->applyProfileAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * Bring this company's licences up to what its profile recommends.
     *
     * GRANT ONLY. It never revokes and never writes `enabled`, which makes it
     * safe to run twice and safe to run on a company that has switched things
     * off. Revoking is a billing event and belongs to a toggle somebody
     * deliberately moved, not to a button that says "apply".
     *
     * This is the answer to "we set the profile up wrong" and to "they changed
     * shape" — the licences re-sync on demand while the tenant database, seeded
     * once at provisioning, is left alone.
     */
    private function applyProfileAction(): Action
    {
        return Action::make('applyProfileLicences')
            ->label('Apply profile licences')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(fn (): bool => $this->record->hasProfile()
                && (auth()->user()?->isSuperAdmin() ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Apply the '.$this->record->profileLabel().' profile')
            ->modalDescription(fn (): HtmlString => new HtmlString($this->applyProfileSummary()))
            ->modalSubmitActionLabel('Grant')
            ->action(fn () => $this->applyProfileLicences());
    }

    /**
     * Exactly what the button will do, listed before it is pressed — the same
     * rule the Modules page and the fiscal-year close modal follow.
     */
    private function applyProfileSummary(): string
    {
        $grant = $this->modulesToGrant();

        if ($grant === []) {
            return 'Everything the <strong>'.e($this->record->profileLabel()).'</strong> profile '
                .'recommends is already licensed. Nothing to do.';
        }

        return 'Grants '.e($this->labels($grant)).'.<br><br>'
            .'Nothing is revoked, and no module the company has switched off is switched back on — '
            .'their own choices survive this.';
    }

    /**
     * The profile's modules and everything those require, minus what is already
     * licensed and minus Core, which is never a grant.
     *
     * Requirements are pulled in here rather than left to the cascade so the
     * confirmation modal names them: granting Billing quietly also granting
     * Invoicing is fine, doing it without saying so is not.
     *
     * @return array<int, string>
     */
    private function modulesToGrant(): array
    {
        $state = modules()->stateFor($this->record->getKey());
        $wanted = [];

        foreach (CompanyProfiles::modules($this->record->profile) ?? [] as $module) {
            $wanted[] = $module;
            $wanted = array_merge($wanted, $this->allRequirements($module));
        }

        return array_values(array_filter(
            array_unique($wanted),
            fn (string $module): bool => in_array($module, Modules::names(), true)
                && ! Modules::isLocked($module)
                && ! ($state[$module]['licensed'] ?? false),
        ));
    }

    private function applyProfileLicences(): void
    {
        $grant = $this->modulesToGrant();

        if ($grant === []) {
            Notification::make()
                ->title('Already licensed.')
                ->body('The '.$this->record->profileLabel().' profile adds nothing this company does not have.')
                ->success()
                ->send();

            return;
        }

        $state = modules()->stateFor($this->record->getKey());
        $desired = [];

        foreach (Modules::names() as $module) {
            if (Modules::isLocked($module)) {
                continue;
            }

            // The union, never the profile alone: a module granted outside the
            // profile was a deliberate sale and must survive this.
            $desired[$module] = ($state[$module]['licensed'] ?? false)
                || in_array($module, $grant, true);
        }

        $this->writeLicences($desired);

        // Re-read, so the toggles below the button show what just happened.
        $this->fillForm();

        Notification::make()
            ->title('Profile licences applied.')
            ->body('Granted '.$this->labels($grant).'.')
            ->success()
            ->send();
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
        $this->writeLicences($this->licenceState);
    }

    /**
     * Write a desired licence set, closed under requirements, and log what
     * changed.
     *
     * Shared by the form save and by "Apply profile licences" so there is one
     * definition of what granting means — in particular the `enabled` rule
     * below, which is the whole reason a revoke is not destructive.
     *
     * @param  array<string, bool>  $desired
     */
    private function writeLicences(array $desired): void
    {
        foreach ($this->cascade($desired) as $module => $licensed) {
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
     * @param  array<int, string>  $modules
     */
    private function labels(array $modules): string
    {
        return implode(', ', array_map(fn (string $m): string => Modules::label($m), $modules));
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
