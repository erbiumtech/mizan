<?php

namespace App\Modules\PersonalFinance\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Models\PersonalTaxProfile;
use App\Modules\PersonalFinance\Models\TaxSchedule;
use App\Modules\PersonalFinance\Services\PersonalTaxService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use RuntimeException;
use UnitEnum;

/**
 * What one person's income would cost them in tax for a year, and how that
 * figure was arrived at.
 *
 * An estimate, and the screen says so rather than burying it: it works from what
 * has been recorded here, and knows nothing about tax already deducted at
 * source, credits, deductible allowances or the surcharge on high salaried
 * income.
 *
 * The service raises rather than returning zero when no bracket matches an
 * amount. That is caught here and shown as a message, because "no schedule is
 * seeded for this year" and "you owe nothing" have to look different — payroll's
 * equivalent bug was invisible for exactly as long as they looked the same.
 */
class TaxEstimate extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.personal-tax-estimate';

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $title = 'Tax Estimate';

    protected static ?int $navigationSort = 5;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('PersonalFinanceView') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'fiscal_year_id' => $this->defaultFiscalYearId(),
        ]);
    }

    /**
     * Open on a year this can actually work out.
     *
     * Not simply FiscalYear::current(): rates are seeded per year, and the
     * active year is routinely the one whose Finance Act has not been enacted
     * yet — so defaulting to it greets everybody with "no brackets for this
     * year" instead of an estimate. Prefer the active year when it has rates,
     * otherwise the most recent year that does.
     *
     * Picking a year with no rates is still allowed; it just has to be a choice
     * somebody made rather than where the screen dumps them.
     */
    private function defaultFiscalYearId(): ?int
    {
        $current = FiscalYear::current();

        if ($current && TaxSchedule::where('fiscal_year_id', $current->id)->exists()) {
            return $current->id;
        }

        return FiscalYear::query()
            ->whereIn('id', TaxSchedule::select('fiscal_year_id'))
            ->orderByDesc('start_date')
            ->value('id') ?? $current?->id;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label('Tax year')
                    ->options(FiscalYear::orderByDesc('start_date')->pluck('name', 'id'))
                    ->live()
                    ->helperText('July to June. FBR names this period for the year it ends in, so this app\'s 2025-2026 is FBR\'s Tax Year 2026.'),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('personal-tax-estimate', 'Tax Estimate: Help'),
        ];
    }

    /**
     * @return array{estimate: ?array<string, mixed>, error: ?string, filer_status: ?string}
     */
    public function getEstimate(): array
    {
        $fiscalYearId = $this->data['fiscal_year_id'] ?? null;

        if (! $fiscalYearId) {
            return ['estimate' => null, 'error' => null, 'filer_status' => null];
        }

        $profile = PersonalTaxProfile::where('fiscal_year_id', $fiscalYearId)->first();

        try {
            $estimate = app(PersonalTaxService::class)->estimate((int) $fiscalYearId);
        } catch (RuntimeException $e) {
            return [
                'estimate' => null,
                'error' => $e->getMessage(),
                'filer_status' => $profile?->filer_status,
            ];
        }

        return [
            'estimate' => $estimate,
            'error' => null,
            'filer_status' => $profile?->filer_status,
        ];
    }
}
