<?php

namespace App\Modules\Core\Filament\Pages;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Support\ModuleMap;
use App\Support\Modules as Registry;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * The company's own activation page: which of the modules it has been licensed
 * are switched on right now.
 *
 * Only licensed modules appear. An unlicensed one is not shown as a locked
 * toggle — the company cannot act on it, so offering a control that always fails
 * would be worse than not listing it. Granting licences is the super admin's
 * surface, on the Company edit page.
 *
 * Core never appears at all: it holds this page, Users and Roles, so a company
 * able to switch it off could lock itself out of its own administration.
 */
class Modules extends Page
{
    protected string $view = 'filament.pages.modules';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $title = 'Modules';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    /**
     * Administrator only, mirroring CompanySettings. Not gated on a module: this
     * page belongs to Core, which is always available.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Administrator') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->currentState());
    }

    public function form(Schema $schema): Schema
    {
        $licensed = $this->licensedModules();

        if ($licensed === []) {
            return $schema->statePath('data')->components([
                Section::make('Modules')
                    ->schema([
                        Placeholder::make('none')
                            ->hiddenLabel()
                            ->content('No optional modules are licensed for this company yet. Contact your administrator to have one added.'),
                    ]),
            ]);
        }

        return $schema->statePath('data')->components([
            Section::make('Modules')
                ->description('Switch off a module to hide it from this company. Nothing is deleted — switching it back on restores everything, unchanged.')
                ->schema([
                    ...array_map(fn (string $module) => $this->toggleFor($module), $licensed),

                    // The consequences of the current toggle state, before the
                    // save rather than after it: the same lesson as the
                    // fiscal-year close modal, which lists every blocker up front
                    // instead of failing one attempt at a time.
                    Placeholder::make('consequences')
                        ->label('On save')
                        ->content(fn (Get $get) => new HtmlString($this->consequenceSummary($get)))
                        ->live(),
                ]),
        ]);
    }

    private function toggleFor(string $module): Toggle
    {
        $requires = Registry::requirements($module);
        $dependents = array_intersect(Registry::dependents($module), $this->licensedModules());

        $notes = [Registry::registry()[$module]['description'] ?? ''];

        if ($requires !== []) {
            $notes[] = 'Requires '.$this->labels($requires).'.';
        }

        if ($dependents !== []) {
            $notes[] = 'Needed by '.$this->labels($dependents).'.';
        }

        // What the company is hiding, in its own terms. Hiding data that exists
        // is fine; doing it without being told is not.
        $notes[] = number_format($this->recordCount($module)).' records.';

        return Toggle::make($module)
            ->label(Registry::label($module))
            ->helperText(trim(implode(' ', array_filter($notes))))
            ->live();
    }

    /**
     * What saving the current state will additionally do — requirements that get
     * pulled in, dependents that get switched off.
     */
    private function consequenceSummary(Get $get): string
    {
        $desired = [];

        foreach ($this->licensedModules() as $module) {
            $desired[$module] = (bool) $get($module);
        }

        [, $pulledIn, $switchedOff, $refused] = $this->cascade($desired, $this->currentState());

        $lines = [];

        if ($pulledIn !== []) {
            $lines[] = 'Also switching <strong>on</strong>: '.e($this->labels($pulledIn)).' — required by what you enabled.';
        }

        if ($switchedOff !== []) {
            $lines[] = 'Also switching <strong>off</strong>: '.e($this->labels($switchedOff)).' — these depend on what you disabled.';
        }

        if ($refused !== []) {
            $lines[] = 'Cannot switch on: '.e($this->labels($refused)).' — a module it requires is not licensed for this company.';
        }

        if ($lines === []) {
            return '<span class="text-gray-500">No side effects.</span>';
        }

        return implode('<br>', $lines);
    }

    /**
     * What is stored right now, for the licensed modules only.
     *
     * @return array<string, bool>
     */
    private function currentState(): array
    {
        $companyId = $this->tenantId();

        return collect($this->licensedModules())
            ->mapWithKeys(fn (string $module) => [$module => modules()->enabledFor($companyId, $module)])
            ->all();
    }

    /**
     * Resolve a desired state into a consistent one.
     *
     * Dependencies are hard here, at toggle time — unlike the runtime
     * degradation in the posting services, which have to cope with a licence
     * disappearing under live data.
     *
     * This works from what *changed*, not from the desired state alone, and that
     * distinction matters: switching Invoicing on while Accounting is off is a
     * contradiction, and which way it resolves depends entirely on which toggle
     * the user just moved. Enabling Invoicing means "pull Accounting in";
     * disabling Accounting means "take Invoicing with it". Reading only the end
     * state, both look identical.
     *
     * @param  array<string, bool>  $desired  what the form says
     * @param  array<string, bool>  $current  what is stored now
     * @return array{0: array<string, bool>, 1: array<int, string>, 2: array<int, string>, 3: array<int, string>}
     */
    private function cascade(array $desired, array $current): array
    {
        $pulledIn = [];
        $switchedOff = [];
        $refused = [];

        // Switched off: everything that depends on it goes too, rather than being
        // left half-working. dependents() is already transitive.
        foreach ($desired as $module => $on) {
            if ($on || ! ($current[$module] ?? false)) {
                continue;
            }

            foreach (Registry::dependents($module) as $dependent) {
                if (($desired[$dependent] ?? false) === true) {
                    $desired[$dependent] = false;
                    $switchedOff[] = $dependent;
                }
            }
        }

        // Switched on: its requirements come with it. Applied after the off-pass
        // so an explicit enable wins the contradiction above.
        foreach ($desired as $module => $on) {
            if (! $on || ($current[$module] ?? false)) {
                continue;
            }

            foreach ($this->allRequirements($module) as $required) {
                if (! array_key_exists($required, $desired)) {
                    continue;   // unlicensed — handled by the consistency pass
                }

                if (! $desired[$required]) {
                    $desired[$required] = true;
                    $pulledIn[] = $required;
                    $switchedOff = array_values(array_diff($switchedOff, [$required]));
                }
            }
        }

        // Nothing may be left on with a requirement that is not. Catches the
        // unlicensed-requirement case, where the module simply cannot go on.
        for ($pass = 0; $pass <= count($desired); $pass++) {
            $changed = false;

            foreach ($desired as $module => $on) {
                if (! $on) {
                    continue;
                }

                foreach (Registry::requirements($module) as $required) {
                    $available = $desired[$required]
                        ?? modules()->enabledFor($this->tenantId(), $required);

                    if (! $available) {
                        $desired[$module] = false;
                        $refused[] = $module;
                        $changed = true;
                    }
                }
            }

            if (! $changed) {
                break;
            }
        }

        return [
            $desired,
            array_values(array_diff(array_unique($pulledIn), $refused)),
            array_values(array_diff(array_unique($switchedOff), $refused)),
            array_values(array_unique($refused)),
        ];
    }

    /**
     * Transitive requirements — enabling Invoicing needs Accounting, and anything
     * Accounting itself would need.
     *
     * @return array<int, string>
     */
    private function allRequirements(string $module): array
    {
        $required = [];

        foreach (Registry::requirements($module) as $direct) {
            $required[] = $direct;
            $required = array_merge($required, $this->allRequirements($direct));
        }

        return array_values(array_unique($required));
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $companyId = $this->tenantId();

        if ($companyId === null) {
            Notification::make()->title('No current company.')->danger()->send();

            return;
        }

        $desired = [];

        foreach ($this->licensedModules() as $module) {
            $desired[$module] = (bool) ($state[$module] ?? false);
        }

        $before = modules()->stateFor($companyId);

        [$desired, $pulledIn, $switchedOff, $refused] = $this->cascade($desired, $this->currentState());

        foreach ($desired as $module => $on) {
            CompanyModule::updateOrCreate(
                ['company_id' => $companyId, 'module' => $module],
                ['enabled' => $on],
            );
        }

        modules()->flush();

        $this->logChanges($companyId, $before, $desired);

        $this->form->fill($desired);

        $body = [];

        if ($pulledIn !== []) {
            $body[] = 'Also switched on: '.$this->labels($pulledIn).'.';
        }

        if ($switchedOff !== []) {
            $body[] = 'Also switched off: '.$this->labels($switchedOff).'.';
        }

        if ($refused !== []) {
            $body[] = 'Could not switch on '.$this->labels($refused).': a module it requires is not licensed.';
        }

        Notification::make()
            ->title('Modules updated.')
            ->body(implode(' ', $body) ?: null)
            ->success()
            ->send();
    }

    /**
     * "Who turned Payroll off, and when" is the first question every such
     * incident opens with.
     *
     * @param  array<string, array{licensed: bool, enabled: bool}>  $before
     * @param  array<string, bool>  $after
     */
    private function logChanges(int|string $companyId, array $before, array $after): void
    {
        foreach ($after as $module => $on) {
            $was = ($before[$module]['licensed'] ?? false) && ($before[$module]['enabled'] ?? false);

            if ($was === $on) {
                continue;
            }

            activity()
                ->performedOn(Company::find($companyId))
                ->withProperties(['module' => $module, 'enabled' => $on])
                ->log(($on ? 'Enabled' : 'Disabled').' the '.Registry::label($module).' module');
        }
    }

    /**
     * Licensed, and not Core — the set this company can actually act on.
     *
     * @return array<int, string>
     */
    private function licensedModules(): array
    {
        return modules()->activatable($this->tenantId());
    }

    /**
     * The same resolution the gate, the middleware and the Gate::before deny use,
     * rather than reading Filament's tenant directly: inside a Livewire request
     * the panel tenant is not always resolvable, and a null company reads as
     * "everything available", which silently inverted the dependency cascade
     * here — every module looked already-enabled, so nothing counted as newly
     * switched on and no requirement was ever pulled in.
     */
    private function tenantId(): int|string|null
    {
        return modules()->companyIdsFor(auth()->user())[0] ?? null;
    }

    /**
     * Total rows across the module's own models, so an admin can see the size of
     * what they are hiding.
     */
    private function recordCount(string $module): int
    {
        $total = 0;

        foreach (ModuleMap::models($module) as $model) {
            try {
                $total += $model::query()->count();
            } catch (\Throwable) {
                // A tenant whose schema predates the model, or a landlord model
                // read outside its connection. A missing count must not take the
                // page down.
            }
        }

        return $total;
    }

    /**
     * @param  array<int, string>  $modules
     */
    private function labels(array $modules): string
    {
        return implode(', ', array_map(fn (string $m) => Registry::label($m), $modules));
    }
}
