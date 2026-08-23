<?php

namespace App\Modules\ConstructionField\Filament\Resources\DelayEvents\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\DelayEvent;
use App\Modules\ConstructionField\Services\DelayEventService;
use App\Support\TenantDb;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What happened, when, and whose risk it was.
 *
 * **The notice date is not on this form**, and that is the design: it is computed from the date of the event and the
 * contract's notice period, and stored so a later edit to that period cannot move a deadline somebody has been warned
 * about. What the form does instead is *show* the date it will produce, before the event is saved — because the useful
 * moment to learn that notice is due in five days is while typing, not in tomorrow's email.
 *
 * The claimed figures are deliberately optional. §13's clock exists precisely so events are raised early, when nobody
 * knows yet what the delay is worth — an event that could not be recorded without a figure is an event recorded late or
 * not at all.
 */
class DelayEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What happened')
                    ->columns(2)
                    ->schema([
                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),

                        Select::make('contract_id')
                            ->label('Under contract')
                            ->options(fn (callable $get): array => static::contracts($get('job_id')))
                            ->searchable()
                            ->live()
                            ->visible(fn (): bool => modules()->enabled('construction_contracts'))
                            // The contract decides the notice period. Without one the shipped default applies, which is
                            // the ordinary case for a job whose commercial side is kept elsewhere.
                            ->helperText('Sets the notice period from the contract\'s own terms. Leave blank to use the default.')
                            ->placeholder('None — use the default notice period'),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('What a reader in six months needs to recognise it by. "Delay" on its own is a row nobody can assess.'),

                        Select::make('cause_category')
                            ->label('Cause')
                            ->options(DelayEvent::CAUSES)
                            ->required()
                            ->searchable()
                            // Who bears the risk is the first thing an assessment turns on.
                            ->helperText('Employer risk usually buys time and money; contractor risk buys neither; neutral usually buys time alone.'),

                        DatePicker::make('occurred_on')
                            ->label('Occurred on')
                            ->native(false)
                            ->required()
                            ->default(now())
                            ->live()
                            ->helperText('The notice clock runs from this date, not from today.'),

                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('The notice clock')
                    ->description('§13: a valid claim lost to a missed notice is the commonest way a contractor gives money away, and it fails in silence. This is the date that stops it.')
                    ->schema([
                        Placeholder::make('notice_clock')
                            ->label('Notice will be due')
                            ->content(fn (callable $get, ?DelayEvent $record): string => static::clock($get, $record)),
                    ]),

                Section::make('What is claimed')
                    ->description('Optional, and usually unknown this early — which is the point. An event that could not be raised without a figure is an event raised late or not at all.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('claimed_days')
                            ->label('Days claimed')
                            ->numeric(),

                        TextInput::make('cost_claimed')
                            ->label('Cost claimed')
                            ->numeric(),

                        Select::make('concurrent_with_delay_event_id')
                            ->label('Concurrent with')
                            ->options(fn (callable $get, ?DelayEvent $record): array => DelayEvent::query()
                                ->where('job_id', $get('job_id'))
                                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->orderByDesc('id')->get()
                                ->mapWithKeys(fn (DelayEvent $e): array => [$e->getKey() => $e->displayName()])
                                ->all())
                            ->searchable()
                            ->columnSpanFull()
                            // §13: "concurrency is the whole argument in most extension-of-time disputes".
                            ->helperText('Another event that delayed the same work at the same time. Recorded, never interpreted — what concurrency means for entitlement depends on the contract.'),

                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The contracts on a job, read without naming the `Contract` model.
     *
     * `construction_field` requires only `construction` (§18), so naming that class would put `construction_contracts`
     * in this module's import graph and make the licensing claim false. Two columns out of a table is all a picker
     * needs.
     *
     * @return array<int, string>
     */
    private static function contracts(int|string|null $jobId): array
    {
        if ($jobId === null || ! modules()->enabled('construction_contracts')) {
            return [];
        }

        return TenantDb::table('construction_contracts')
            ->where('job_id', $jobId)
            ->orderBy('contract_number')
            ->pluck('contract_number', 'id')
            ->all();
    }

    /**
     * What the clock will say, shown before the event is saved.
     *
     * On an existing event the stored date is printed, because that is the one that counts — recomputing it here would
     * show a different answer from the one the reminder emails are using, which is worse than showing nothing.
     */
    private static function clock(callable $get, ?DelayEvent $record): string
    {
        if ($record !== null && $record->notice_required_by !== null) {
            $days = $record->daysUntilNoticeDue();

            return $record->notice_required_by->format('d M Y')
                .' — '.($record->noticeGiven()
                    ? 'notice served on '.$record->notice_given_on->format('d M Y')
                        .($record->noticeWasLate() ? ', after the period ran out' : '')
                    : ($days < 0
                        ? abs($days).' day(s) overdue, with nothing served'
                        : $days.' day(s) left'))
                .'. Based on '.$record->notice_days.' days from the event.';
        }

        if ($get('occurred_on') === null) {
            return 'Set the date the event occurred.';
        }

        $days = app(DelayEventService::class)->noticeDaysFor($get('contract_id'));
        $due = Carbon::parse((string) $get('occurred_on'))->addDays($days);

        return $due->format('d M Y').' — '.$days.' days from the event. '
            .'Frozen onto the event when it is saved, so changing the contract\'s terms later cannot move it.';
    }
}
