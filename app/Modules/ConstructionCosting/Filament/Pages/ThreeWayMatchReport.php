<?php

namespace App\Modules\ConstructionCosting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionCosting\Models\CommitmentLine;
use App\Modules\ConstructionCosting\Services\ThreeWayMatch;
use App\Modules\ConstructionCosting\Support\MatchTolerances;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use InvalidArgumentException;
use UnitEnum;

/**
 * Ordered, received, invoiced — and where the three disagree. `docs/construction-management-plan.md` §5.
 *
 * **A list somebody works, not a number on a dashboard.** The match is computed from the documents every time this page
 * is opened; what the page adds is the decision §5 keeps: accepting a variance, with a name and a reason on it.
 *
 * **It blocks nothing.** §5 puts the control at acceptance rather than at payment, and the reason is practical: a match
 * variance that stopped an invoice being paid is how a site ends up with a supplier refusing the next delivery over
 * four thousand nobody could authorise.
 *
 * The tolerances that decide what appears here are the company's own, and the page says what they currently are —
 * a report whose threshold is invisible is one people argue with rather than act on.
 */
class ThreeWayMatchReport extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.construction.three-way-match';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $title = 'Three-way match';

    protected static ?int $navigationSort = 48;

    public ?array $data = [];

    /**
     * Its own permission check as well as the trait's — a page with its own `canAccess()` shadows it otherwise,
     * which is the trap `docs/new-module-checklist.md` §11 names.
     */
    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->can('ConstructionCommitmentView') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill(['job_id' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('job_id')
                    ->label('Job')
                    ->options(fn (): array => Job::query()->orderBy('code')->get()
                        ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                        ->all())
                    ->searchable()
                    ->placeholder('Every job')
                    ->live(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-three-way-match', 'Three-way match: Help')];
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        $job = ($id = $this->data['job_id'] ?? null) ? Job::query()->find($id) : null;

        return app(ThreeWayMatch::class)->variances($job)->all();
    }

    /**
     * What the thresholds currently are, printed on the page.
     *
     * A report whose threshold is invisible is one people argue with instead of acting on — "why is this on here" is
     * the first question, and it should not need somebody to open a settings screen to answer.
     *
     * @return array<string, string>
     */
    public function tolerances(): array
    {
        return [
            'Quantity' => rtrim(rtrim(number_format(MatchTolerances::quantityPercent(), 2), '0'), '.').'%',
            'Price' => rtrim(rtrim(number_format(MatchTolerances::pricePercent(), 2), '0'), '.').'%',
            'Ignored below' => number_format(MatchTolerances::minimumAmount(), 2),
        ];
    }

    /** What the whole list is worth, so the report is a figure somebody can be asked about. */
    public function total(): float
    {
        return round(array_sum(array_map(
            fn (array $row): float => abs($row['price_variance'])
                + abs(($row['quantity_variance'] ?? 0) * (float) ($row['line']->rate ?? 0)),
            $this->rows(),
        )), 2);
    }

    /** Accept a variance: the one thing this page writes, and the reason is not optional. */
    public function acceptAction(): Action
    {
        return Action::make('accept')
            ->label('Accept')
            ->icon('heroicon-o-check')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->can('ConstructionVarianceAccept') ?? false)
            ->modalHeading('Accept this variance')
            ->modalDescription('It stops appearing on this report and keeps your name and your reason. That record is the answer when somebody asks in six months why the job carries more than it was ordered at.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why it is acceptable')
                    ->rows(3)
                    ->required()
                    ->helperText('"Supplier substituted 20mm for 16mm at our request, agreed with the QS on the 14th" — not "ok".'),
            ])
            ->action(function (array $data, array $arguments): void {
                try {
                    app(ThreeWayMatch::class)->accept(
                        CommitmentLine::query()->findOrFail($arguments['line']),
                        $data['reason'],
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Accepted.')
                    ->body('It is off the report, with your name and reason against it.')
                    ->send();
            });
    }
}
