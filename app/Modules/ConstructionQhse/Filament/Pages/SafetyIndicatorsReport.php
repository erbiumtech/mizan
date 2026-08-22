<?php

namespace App\Modules\ConstructionQhse\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionQhse\Services\SafetyIndicators;
use App\Modules\ConstructionQhse\Support\ExposureHours;
use App\Modules\ConstructionQhse\Support\SafetyRate;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * The safety indicators — `docs/construction-management-plan.md` §17.6, and the page Phase 10 is meant to end on.
 *
 * Phase 10's stated exit condition is "an NCR that proposes a deduction and never applies one, and **a safety page that
 * refuses to print a rate it cannot compute**". This is the second half, and the refusal is the feature.
 *
 * **Nothing here is stored.** §17.6: the lagging indicators are "computed on a report page and never stored". A stored
 * LTIFR is a figure that was true for a period whose incidents were subsequently reclassified — and reclassification is
 * normal, since a first-aid case becomes a lost-time case the day somebody does not come back. A stored one goes stale
 * silently; a computed one is simply right.
 *
 * **Every rate prints its base**, because §17.6 says a frequency rate without one "gets compared against a competitor's
 * figure computed on a different one, and 1,000,000 against 200,000 is a factor of five with both called *the
 * standard*". `SafetyRate` makes that structural rather than a habit of the view: there is no way to get the figure out
 * without the base.
 *
 * **And the denominator is the point of the whole page.** §17.6 calls the missing-exposure case "a genuine silent
 * failure rather than a graceful degradation" — with no diary the denominator is zero, every rate renders as `0.00`, and
 * a perfect safety record is the most dangerous wrong answer this application could give. So the page prints
 * "insufficient exposure data" and the sentence explaining what to do, and it prints that *instead of* a rate rather
 * than beside one.
 *
 * The mirror-image failure gets the same treatment: counting the same people from the diary *and* Timesheets halves every
 * rate, so the job names one source and this page prints which one it used — including when the job has named neither,
 * which is a third state and not a default.
 *
 * The leading indicators need no denominator and are the ones worth acting on. They are on the same page deliberately:
 * a page that shows only lagging figures is read once a month by somebody writing a report, and a page that shows both
 * gets read by somebody who can still change the outcome.
 */
class SafetyIndicatorsReport extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.construction.safety-indicators';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string|UnitEnum|null $navigationGroup = 'Quality & Safety';

    protected static ?string $title = 'Safety indicators';

    protected static ?int $navigationSort = 70;

    public ?array $data = [];

    /**
     * Its own permission rather than a resource's, and `moduleIsAvailable()` explicitly — a page that defines
     * `canAccess()` shadows the trait's, which is the trap `docs/new-module-checklist.md` §11 names.
     */
    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('ConstructionIncidentView') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill([
            'job_id' => Job::query()->live()->orderBy('code')->value('id'),
            // A rolling twelve months, because a frequency rate on one month of a small site is arithmetic on two
            // incidents and reads as a trend. The window is editable; the default should not invite the mistake.
            'from' => now()->subYear()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'base' => (int) config('construction.qhse.rate_base', 1_000_000),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(4)
            ->components([
                Select::make('job_id')
                    ->label('Job')
                    ->options(fn (): array => Job::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->live()
                    ->selectablePlaceholder(false),

                DatePicker::make('from')->label('From')->live()->native(false),
                DatePicker::make('to')->label('To')->live()->native(false),

                /*
                 * **The base, selectable and printed.**
                 *
                 * Here rather than only in configuration because the same company reports on two bases to two
                 * audiences: a Gulf client's pack asks for a million and a UK insurer asks for a hundred thousand. The
                 * defence against §17.6's complaint is not one base for everyone, it is the base being visible on the
                 * face of the figure — which `SafetyRate` guarantees whichever of these is chosen.
                 */
                Select::make('base')
                    ->label('Rate base')
                    ->options([
                        100_000 => 'per 100,000 hours',
                        200_000 => 'per 200,000 hours (100 workers/year)',
                        1_000_000 => 'per 1,000,000 hours',
                    ])
                    ->live()
                    ->selectablePlaceholder(false)
                    ->helperText('Printed with every rate below. The same site is a factor of ten apart on two of these.'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-safety-indicators', 'Safety indicators: Help')];
    }

    public function selectedJob(): ?Job
    {
        return ($id = $this->data['job_id'] ?? null) ? Job::query()->find($id) : null;
    }

    public function base(): int
    {
        return (int) ($this->data['base'] ?? config('construction.qhse.rate_base', 1_000_000));
    }

    /** The window, defaulted so a half-filled form still computes something honest. */
    private function window(): array
    {
        return [
            $this->data['from'] ?? now()->subYear()->toDateString(),
            $this->data['to'] ?? now()->toDateString(),
        ];
    }

    /**
     * The denominator, as its own thing on the page.
     *
     * Shown above the rates rather than under them, because when it is missing it is the only thing on the page worth
     * reading and every rate below it is a refusal.
     */
    public function exposure(): ?ExposureHours
    {
        $job = $this->selectedJob();

        if ($job === null) {
            return null;
        }

        [$from, $to] = $this->window();

        return app(SafetyIndicators::class)->exposureHours($job, $from, $to);
    }

    /**
     * §17.6's lagging indicators, each a `SafetyRate` that is either a figure with its base or a refusal with a reason.
     *
     * @return array<int, SafetyRate>
     */
    public function rates(): array
    {
        $job = $this->selectedJob();

        if ($job === null) {
            return [];
        }

        [$from, $to] = $this->window();
        $indicators = app(SafetyIndicators::class);
        $base = $this->base();

        return [
            $indicators->lostTimeInjuryFrequencyRate($job, $from, $to, $base),
            $indicators->totalRecordableIncidentRate($job, $from, $to, $base),
            $indicators->accidentFrequencyRate($job, $from, $to, $base),
            $indicators->severityRate($job, $from, $to, $base),
        ];
    }

    /**
     * The near-miss ratio, which sits between the two halves of the page.
     *
     * Lagging in that it counts what has happened, leading in that §17.3 calls it "the indicator that predicts the next
     * one" — and it needs no exposure hours, so it survives the absence that silences everything above it.
     *
     * @return array{near_misses: int, lost_time: int, ratio: float|null}|null
     */
    public function nearMissRatio(): ?array
    {
        $job = $this->selectedJob();

        if ($job === null) {
            return null;
        }

        [$from, $to] = $this->window();

        return app(SafetyIndicators::class)->nearMissRatio($job, $from, $to);
    }

    /**
     * §17.6's leading indicators.
     *
     * @return array<string, mixed>
     */
    public function leading(): array
    {
        $job = $this->selectedJob();

        if ($job === null) {
            return [];
        }

        [$from, $to] = $this->window();

        return app(SafetyIndicators::class)->leadingIndicators($job, $from, $to);
    }
}
