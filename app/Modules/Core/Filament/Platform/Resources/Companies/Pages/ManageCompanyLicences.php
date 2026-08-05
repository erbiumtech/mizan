<?php

namespace App\Modules\Core\Filament\Platform\Resources\Companies\Pages;

use App\Modules\Core\Filament\Platform\Resources\Companies\CompanyResource;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Support\Modules as Registry;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What a company has been sold.
 *
 * Two flags, two owners, and this page is only ever the first: `licensed` is the super
 * admin's grant, `enabled` is the company's own choice about what to show right now. A
 * module is available only when both are true, so revoking a licence takes the module away
 * without touching what the company had switched on — re-granting it restores their choice
 * instead of silently resetting it.
 *
 * Driven by the registry rather than by the `company_modules` rows, because a module that
 * has never been granted has no row and is exactly the one somebody came here to grant. A
 * relation manager over the rows could only ever show what was already decided.
 */
class ManageCompanyLicences extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CompanyResource::class;

    protected static ?string $title = 'Licences';

    protected string $view = 'filament.pages.company-licences';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->form->fill($this->currentState());
    }

    /** @return array<string, bool> */
    protected function currentState(): array
    {
        $state = modules()->stateFor($this->company()->getKey());

        $licensed = [];

        foreach (Registry::names() as $module) {
            $licensed[$module] = (bool) ($state[$module]['licensed'] ?? false);
        }

        return $licensed;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Modules this company has bought')
                    ->description('Granting a module brings in whatever it cannot run without. Revoking one '
                        .'takes the modules that depend on it with it — otherwise they would be licensed and '
                        .'broken, which is worse than absent.')
                    ->schema(array_map(
                        fn (string $module): Toggle => $this->toggleFor($module),
                        Registry::names(),
                    ))
                    ->columns(2),
            ]);
    }

    protected function toggleFor(string $module): Toggle
    {
        $requires = Registry::requirements($module);

        return Toggle::make($module)
            ->label(Registry::label($module))
            // Core holds the Modules page, Users and Roles. A company without it cannot
            // be administered at all, so it has no toggle rather than a disabled one.
            ->disabled(Registry::isLocked($module))
            ->helperText(match (true) {
                Registry::isLocked($module) => 'Always included — the company could not be administered without it.',
                $requires !== [] => 'Needs '.implode(', ', array_map(
                    fn (string $required): string => Registry::label($required),
                    $requires,
                )).'.',
                default => null,
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save licences')
                // A closure, not the method name as a string: the string form is not
                // invoked by the action, which meant Save quietly did nothing.
                ->action(fn () => $this->save()),

            Action::make('openCompany')
                ->label('Open this company')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => "/admin/{$this->company()->slug}")
                // What the company's own administrator sees is the other panel's job.
                // A link, rather than switching tenants mid-request to render one table
                // here: that is how a page ends up reading one company and writing
                // another.
                ->openUrlInNewTab(),
        ];
    }

    public function save(): void
    {
        $desired = $this->cascade((array) $this->data);

        foreach ($desired['licences'] as $module => $licensed) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company()->getKey(), 'module' => $module],
                // Only `licensed`. `enabled` is the company's own switch and is left
                // exactly as they left it.
                ['licensed' => $licensed],
            );
        }

        modules()->flush();

        $this->form->fill($this->currentState());

        $body = [];

        if ($desired['pulled_in'] !== []) {
            $body[] = 'Also granted, because the modules you granted need them: '
                .implode(', ', array_map([Registry::class, 'label'], $desired['pulled_in'])).'.';
        }

        if ($desired['revoked'] !== []) {
            $body[] = 'Also revoked, because they cannot run without what you removed: '
                .implode(', ', array_map([Registry::class, 'label'], $desired['revoked'])).'.';
        }

        Notification::make()
            ->success()
            ->title("Licences saved for {$this->company()->name}")
            ->body($body === [] ? null : implode(' ', $body))
            ->send();
    }

    /**
     * A licence set that makes sense: nothing granted without its requirements, nothing
     * left granted whose requirements were taken away.
     *
     * Both directions are needed. Granting Invoicing without Accounting sells something
     * that cannot post; revoking Accounting while Invoicing stays granted leaves the
     * company paying for a module that fails on its first invoice.
     *
     * Where the two collide — Accounting switched off while Invoicing is on — the switch
     * that was *just moved* wins, and Invoicing goes with it. Inferring the opposite would
     * mean a licence the admin explicitly revoked came back because something else needed
     * it, which is the one outcome they would never expect from turning a toggle off.
     *
     * @param  array<string, bool>  $desired
     * @return array{licences: array<string, bool>, pulled_in: array<int, string>, revoked: array<int, string>}
     */
    protected function cascade(array $desired): array
    {
        $current = $this->currentState();
        $licences = [];
        $switchedOff = [];

        foreach (Registry::names() as $module) {
            if (Registry::isLocked($module)) {
                $licences[$module] = true;

                continue;
            }

            $licences[$module] = (bool) ($desired[$module] ?? false);

            // Switched *off*, not merely absent: a module this company has never been
            // granted is not a decision to withhold it, and must still be pulled in as
            // something another module needs. Only a licence being taken away outranks an
            // inferred grant.
            if (! $licences[$module] && ($current[$module] ?? false)) {
                $switchedOff[] = $module;
            }
        }

        $pulledIn = [];
        $revoked = [];

        // What is granted brings in what it needs, transitively — except anything the
        // admin has just switched off, which stays off and takes its dependents with it in
        // the pass below.
        foreach (array_keys($licences) as $module) {
            if (! $licences[$module]) {
                continue;
            }

            foreach ($this->allRequirements($module) as $required) {
                if (in_array($required, $switchedOff, true) || ($licences[$required] ?? false)) {
                    continue;
                }

                $licences[$required] = true;
                $pulledIn[] = $required;
            }
        }

        // Nothing stays granted whose requirement is not. A fixpoint rather than one pass,
        // because revoking Accounting takes Invoicing, and Invoicing takes Billing.
        for ($pass = 0; $pass <= count($licences); $pass++) {
            $changed = false;

            foreach (array_keys($licences) as $module) {
                if (! $licences[$module]) {
                    continue;
                }

                foreach (Registry::requirements($module) as $required) {
                    if ($licences[$required] ?? false) {
                        continue;
                    }

                    $licences[$module] = false;
                    $revoked[] = $module;
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        return [
            'licences' => $licences,
            // Only what the admin did not already have: "also granted" should list what
            // changed, not restate what was already true.
            'pulled_in' => array_values(array_diff(
                array_unique($pulledIn),
                array_keys(array_filter($current)),
                $revoked,
            )),
            'revoked' => array_values(array_intersect(
                array_unique($revoked),
                array_keys(array_filter($current)),
            )),
        ];
    }

    /** @return array<int, string> */
    protected function allRequirements(string $module): array
    {
        $required = [];

        foreach (Registry::requirements($module) as $direct) {
            $required[] = $direct;
            $required = array_merge($required, $this->allRequirements($direct));
        }

        return array_values(array_unique($required));
    }

    protected function company(): Company
    {
        return $this->getRecord();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
