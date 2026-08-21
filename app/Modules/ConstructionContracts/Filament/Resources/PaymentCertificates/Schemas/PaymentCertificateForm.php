<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Schemas;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use App\Modules\ConstructionCosting\Services\MaterialsOnSite;
use App\Modules\ConstructionField\Models\DailyLogDelivery;
use App\Modules\ConstructionField\Services\DailyLogService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A certificate's header.
 *
 * **None of the money is on this form**, and that is the design: every figure is computed from the lines, the
 * agreed variations and the contract terms, and a typed override would be a certificate whose bottom line nobody
 * can re-derive. What a person chooses here is the period, the claim it answers, and the note.
 *
 * The claim is optional because FIDIC 14.6 lets the Engineer certify without a conforming statement (§10.1).
 */
class PaymentCertificateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The certificate')
                    ->columns(2)
                    ->schema([
                        Select::make('contract_id')
                            ->label('Contract')
                            ->options(fn (): array => Contract::query()
                                ->whereNot('status', Contract::STATUS_DRAFT)
                                ->with('job')
                                ->get()
                                ->mapWithKeys(fn (Contract $c): array => [
                                    $c->getKey() => "{$c->job?->code} · {$c->contract_number} — {$c->title}",
                                ])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabled(fn (?PaymentCertificate $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('progress_claim_id')
                            ->label('Answers claim')
                            ->options(fn (callable $get): array => ProgressClaim::query()
                                ->where('contract_id', $get('contract_id'))
                                ->orderByDesc('period_end')
                                ->get()
                                ->mapWithKeys(fn (ProgressClaim $c): array => [
                                    $c->getKey() => "{$c->claim_number} — to {$c->period_end?->toDateString()}",
                                ])
                                ->all())
                            ->searchable()
                            ->placeholder('None — certifying without a statement')
                            ->helperText('Optional: clause 14.6 lets the certifier issue without a conforming statement.'),

                        DatePicker::make('period_end')
                            ->label('Valuation date')
                            ->required()
                            ->helperText('Every figure on the certificate is "to" this date.'),

                        DatePicker::make('period_start')
                            ->label('Period from'),

                        Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Printed on the certificate.'),
                    ]),

                /*
                 * **Evidence for the materials-on-site line, and deliberately not the line itself** — §6 and §10.
                 *
                 * §6 names the certificate's materials line as the second reason receipt and issue are separate
                 * documents: without both, there is no moment at which material is on site and unconsumed, so the
                 * figure cannot exist. Now it can, and this is where a certifier can see it.
                 *
                 * What is *claimed* for materials on site is a contractual assessment at contract rates against a
                 * schedule line, and it stays where §10 puts it — `cumulative_materials_value` on the certificate
                 * lines, entered by the person assessing it. What this panel shows is what the material **cost**.
                 * Filling the claim in from it would be telling the certifier their assessment had been made for them,
                 * and the two figures are not the same number.
                 *
                 * **Which source the figure came from is printed with it**, added in Phase 9c, and it fixes something
                 * this panel got wrong when it was built: it went absent whenever the cost module was off, and this
                 * module does not require the cost module. A certifier saw no materials-on-site panel at all and could
                 * not tell whether that meant nothing was on site or nothing was being tracked — §18.1's healthy figure
                 * hiding an absence, in the one place a figure is being certified.
                 *
                 * So the panel now shows whichever source exists and names it. The stock ledger is the authority where
                 * there is one, because `remaining_quantity` goes *down* as material is built in; the site diary's
                 * flagged dockets are corroboration, and the only record at all where the cost module is absent.
                 */
                Section::make('Materials on site')
                    ->description('Evidence for the materials line — not the claim itself, which is assessed at contract rates against the schedule.')
                    ->visible(fn (callable $get): bool => static::materialsOnSite($get) !== null
                        || static::siteRecord($get) !== null)
                    ->schema([
                        Placeholder::make('materials_on_site')
                            ->label('Held in the store, at cost')
                            ->visible(fn (callable $get): bool => static::materialsOnSite($get) !== null)
                            ->content(fn (callable $get): string => static::materialsOnSite($get) ?? ''),

                        Placeholder::make('materials_on_site_diary')
                            ->label(fn (callable $get): string => static::materialsOnSite($get) === null
                                ? 'What site recorded — the only source here'
                                : 'What site recorded, beside it')
                            ->visible(fn (callable $get): bool => static::siteRecord($get) !== null)
                            ->content(fn (callable $get): string => static::siteRecord($get) ?? ''),
                    ]),
            ]);
    }

    /**
     * The cost-side figure, or null where there is nothing to show.
     *
     * Asked of `MaterialsOnSite`, which lives in `construction_costing` and holds the Inventory guard itself — so this
     * module reaches its existing guarded sibling and never names Inventory. The coupling already exists for Phase 6c's
     * commitment relief; this adds no new edge to the module graph.
     */
    private static function materialsOnSite(callable $get): ?string
    {
        if (! modules()->enabled('construction_costing')) {
            return null;
        }

        $contract = $get('contract_id') ? Contract::query()->with('job')->find($get('contract_id')) : null;
        $job = $contract?->job;

        if ($job === null) {
            return null;
        }

        $service = app(MaterialsOnSite::class);

        if (! $service->isAvailable() || $job->stock_location_id === null) {
            return null;
        }

        $rows = $service->forJob($job);

        if ($rows === []) {
            return 'Nothing in the store — everything delivered has been issued to the work face.';
        }

        $lines = array_map(
            fn (array $row): string => $row['product']->sku.': '.$row['quantity'].' at '
                .number_format($row['value'], 2),
            $rows,
        );

        return implode('; ', $lines).'. Total at cost: '.number_format($service->valueFor($job), 2).'.';
    }

    /**
     * What the site diary says is standing on site — §16.1's `is_materials_on_site`, read in Phase 9c.
     *
     * **Corroboration, and where the cost module is absent it is the only record there is.** A guarded coupling to
     * `construction_field`: this module requires only `construction`, the diary is a separate purchase, and without it
     * this returns null and the panel is one placeholder again. The direction is the only one available — the field
     * module never names a class of this one — so the graph stays acyclic.
     *
     * **It is not summed into a figure, and the reason is arithmetic.** Nothing on a diary decreases when material is
     * built in, so a total of flagged dockets would overstate what is on site by everything already consumed, growing
     * every month with no error anywhere. It is a count of dockets and their dates, which is what corroboration looks
     * like — and where it is the only source, a certifier is told so in those words rather than shown a total that
     * looks like the stock figure.
     */
    private static function siteRecord(callable $get): ?string
    {
        if (! modules()->enabled('construction_field')) {
            return null;
        }

        $contract = $get('contract_id') ? Contract::query()->with('job')->find($get('contract_id')) : null;
        $job = $contract?->job;

        if ($job === null) {
            return null;
        }

        $deliveries = app(DailyLogService::class)->materialsOnSiteDeliveries($job);

        if ($deliveries->isEmpty()) {
            return null;
        }

        $lines = $deliveries
            ->sortBy(fn (DailyLogDelivery $delivery): string => (string) $delivery->dailyLog?->log_date?->toDateString())
            ->map(fn (DailyLogDelivery $delivery): string => $delivery->dailyLog?->log_date?->format('d M')
                .': '.str($delivery->description)->limit(40)
                .($delivery->quantity ? " ({$delivery->quantity} {$delivery->unit_of_measure})" : ''))
            ->all();

        $note = modules()->enabled('construction_costing')
            ? 'Dockets site flagged as standing on site. Not a total: a diary flag does not come off when the material '
                .'is built in, so the figure above — which does — is the one to certify against.'
            : 'Dockets site flagged as standing on site. There is no stock ledger on this installation, so this is the '
                .'whole of the evidence — a quantity somebody has to verify on site rather than a computed figure.';

        return implode('; ', $lines).'. '.$note;
    }
}
