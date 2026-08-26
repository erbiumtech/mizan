<?php

namespace App\Modules\Core\Filament\Resources\ReportSchedules\Schemas;

use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\ReportSchedule;
use App\Support\Reporting\RelativePeriod;
use Closure;
use Cron\CronExpression;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * What a schedule is, as a form — `docs/reports-expansion-plan.md` Phase 8, items 1, 2 and 3.
 *
 * **The report picker is the hub's own catalogue**, which is what makes a coded report and a built one the
 * same choice here: both are keys the hub already routes on, and the list is filtered by whatever the person
 * filling this in may open. A schedule they could not open the report of is a schedule that would suspend
 * itself on its first run.
 *
 * **The period is a rule and there is no field for two dates** — item 3, and the reason `RelativePeriod` exists.
 * "Monthly on the 1st" for a company whose year starts 1 July is exactly the case that made it necessary.
 *
 * **Recipients are addresses, not a user picker**, and that is item 2 showing through the form: the list is
 * re-authorised at send time, so what is stored has to be the thing a send can be checked against. A user
 * picker would freeze a decision that has to be made later — and it could not express an external recipient,
 * which is a real case with its own permission.
 */
class ReportScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('report_key')
                    ->label('Report')
                    ->options(fn (): array => collect(Reports::catalogue())
                        ->map(fn (array $report): string => $report['label'].' · '.$report['section'])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Every report you can open, including the ones you have built.'),

                Select::make('period')
                    ->label('Period')
                    ->options(RelativePeriod::PERIODS)
                    ->default(RelativePeriod::LAST_MONTH)
                    ->required()
                    ->helperText('Resolved each time it runs, never a fixed pair of dates.'),

                /*
                 * A preset that writes the cron field, rather than a select bound to it.
                 *
                 * Two controls for one column on purpose: "every Monday" is what people ask for and
                 * `0 7 * * 1` is what they then get wrong, but a company that wants something else must not
                 * be stuck with four choices. So the preset fills the expression in and the expression is
                 * what is stored — and it is validated, because a cron nobody can parse is a schedule that
                 * silently never runs.
                 */
                Select::make('timetable_preset')
                    ->label('Timetable')
                    ->options(ReportSchedule::TIMETABLES)
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (filled($state)) {
                            $set('cron', $state);
                        }
                    })
                    ->helperText('Pick one, or write your own expression below.'),

                TextInput::make('cron')
                    ->label('Cron expression')
                    ->placeholder('0 7 * * 1')
                    ->default(array_key_first(ReportSchedule::TIMETABLES))
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! CronExpression::isValidExpression((string) $value)) {
                            $fail('That is not a cron expression this scheduler can read.');
                        }
                    }),

                Select::make('timezone')
                    ->label('Timezone')
                    ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->default(config('app.timezone'))
                    ->searchable()
                    ->required()
                    ->helperText('Whose 07:00 the timetable means.'),

                Select::make('format')
                    ->label('Format')
                    ->options(ReportSchedule::FORMATS)
                    ->default(ReportSchedule::FORMAT_PDF)
                    ->required(),

                TagsInput::make('recipients')
                    ->label('Recipients')
                    ->placeholder('name@company.com')
                    ->required()
                    ->helperText('Checked against this company\'s members each time it runs. An address with no account here needs permission to send outside the company.'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
