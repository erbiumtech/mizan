<?php

namespace App\Modules\ConstructionField\Filament\Resources\Activities\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionField\Models\ProgrammeActivity;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * One activity, entered by hand.
 *
 * **Two things are conspicuously absent from this form and both absences are §13's.**
 *
 * *There is no way to make one date move another.* No predecessor on this form drives a start; the links are recorded on
 * their own tab and nothing reads them to compute anything. A form that recalculated a successor would be the forward
 * pass §13 forbids, wearing a different hat.
 *
 * *Criticality and total float are shown as imported facts, not offered as choices*, unless the activity was typed here
 * in the first place. They come out of the tool that produced the accepted programme, and a screen that let somebody
 * edit an imported critical flag would let them edit a claim.
 */
class ActivityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The activity')
                    ->columns(2)
                    ->schema([
                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live()
                            ->disabled(fn (?ProgrammeActivity $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('wbs_node_id')
                            ->label('WBS node')
                            ->options(fn (callable $get): array => static::wbsNodes($get('job_id')))
                            ->searchable()
                            ->helperText('The commercial breakdown this work belongs to.'),

                        TextInput::make('code')
                            ->label('Activity id')
                            ->required()
                            ->maxLength(255)
                            ->helperText('As printed on the planner\'s programme, so the two can be read side by side.'),

                        Select::make('activity_type')
                            ->options(ProgrammeActivity::TYPES)
                            ->default(ProgrammeActivity::TYPE_TASK)
                            ->required(),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('parent_id')
                            ->label('Within')
                            ->options(fn (callable $get, ?ProgrammeActivity $record): array => static::activities(
                                $get('job_id'),
                                $record?->getKey(),
                            ))
                            ->searchable()
                            ->placeholder('Top level'),

                        Select::make('responsible_contact_id')
                            ->label('Owned by')
                            ->relationship('responsible', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn (): bool => modules()->enabled('invoicing')),
                    ]),

                Section::make('The accepted programme')
                    ->columns(2)
                    ->description('The baseline: what was submitted and agreed. Entitlement is measured against these dates, which is why they are kept apart from the current plan — a single pair would make every re-programme silently retire the delay that caused it.')
                    ->schema([
                        DatePicker::make('baseline_start')->native(false),
                        DatePicker::make('baseline_finish')->native(false),
                        TextInput::make('baseline_revision')
                            ->maxLength(255)
                            ->helperText('Which revision of the accepted programme these dates come from.'),
                        TextInput::make('original_duration_days')->numeric(),
                    ]),

                Section::make('The current plan, and progress')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('planned_start')->native(false),
                        DatePicker::make('planned_finish')->native(false),
                        DatePicker::make('actual_start')->native(false),
                        DatePicker::make('actual_finish')->native(false),

                        TextInput::make('percent_complete')
                            ->numeric()
                            ->default(0)
                            ->helperText('Must be 100 exactly when there is an actual finish, and below it when there is not.'),

                        DatePicker::make('data_date')
                            ->label('Progress as at')
                            ->native(false)
                            ->helperText('40% as at the 1st and as at the 30th are different facts. Without this neither can be compared with last month.'),

                        TextInput::make('remaining_duration_days')->numeric(),

                        TextInput::make('budgeted_value')
                            ->numeric()
                            ->helperText('Deliberately not reconciled to the bill: the programme and the bill are two decompositions of the same job, and forcing agreement produces a fiction somebody has to maintain.'),
                    ]),

                Section::make('Contractual and imported')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_contract_milestone')
                            ->label('A milestone the contract names')
                            ->helperText('Extension of time moves it. Not every finish milestone on a programme is one — most are the planner\'s markers.'),

                        Toggle::make('ld_applies')
                            ->label('Liquidated damages apply')
                            ->helperText('Kept separate on purpose: a contract names dates it does not price. This flag is what turns lateness into money.'),

                        Select::make('milestone_payment_item_id')
                            ->label('Paid against contract item')
                            ->options(fn (callable $get): array => static::contractItems($get('job_id')))
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('construction_contracts'))
                            ->placeholder('Not a payment milestone'),

                        Select::make('source')
                            ->options(ProgrammeActivity::SOURCES)
                            ->default(ProgrammeActivity::SOURCE_MANUAL)
                            ->required()
                            ->disabled(fn (?ProgrammeActivity $record): bool => $record?->isImported() ?? false)
                            ->helperText('Where this row came from. Printed beside the float and the critical flag, because those are somebody else\'s numbers.'),

                        /*
                         * Editable only on an activity typed here. §13's whole argument is that these two come out of
                         * the tool that produced the accepted programme — letting somebody edit an imported critical
                         * flag would let them edit a claim.
                         */
                        TextInput::make('total_float_days')
                            ->numeric()
                            ->disabled(fn (?ProgrammeActivity $record): bool => $record?->isImported() ?? false)
                            ->helperText('Imported, never calculated. There is no float solver here and there will not be one.'),

                        Toggle::make('is_critical')
                            ->label('On the critical path')
                            ->disabled(fn (?ProgrammeActivity $record): bool => $record?->isImported() ?? false)
                            ->helperText('Imported from the scheduling tool. Nothing in this application derives it.'),

                        Placeholder::make('imported_note')
                            ->label('')
                            ->columnSpanFull()
                            ->visible(fn (?ProgrammeActivity $record): bool => $record?->isImported() ?? false)
                            ->content(fn (?ProgrammeActivity $record): string => 'Imported from '
                                .($record?->sourceLabel() ?? '')
                                .($record?->external_id ? " (id {$record->external_id})" : '')
                                .'. Float and criticality are that tool\'s figures and are read-only here — re-import to change them.'),

                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    /** @return array<int, string> */
    private static function wbsNodes(int|string|null $jobId): array
    {
        if ($jobId === null) {
            return [];
        }

        return WbsNode::query()
            ->where('job_id', $jobId)
            ->orderBy('code')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
            ->all();
    }

    /**
     * The job's other activities, for the parent picker — excluding this one, which cannot contain itself.
     *
     * @return array<int, string>
     */
    private static function activities(int|string|null $jobId, int|string|null $exclude): array
    {
        if ($jobId === null) {
            return [];
        }

        return ProgrammeActivity::query()
            ->where('job_id', $jobId)
            ->when($exclude, fn ($q) => $q->whereKeyNot($exclude))
            ->orderBy('code')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (ProgrammeActivity $activity): array => [$activity->getKey() => $activity->displayName()])
            ->all();
    }

    /**
     * The contract's priced lines, read out of the table.
     *
     * `construction_contracts` is guarded — this module requires only `construction` — so the picker asks the query
     * builder and never names `ContractItem`.
     *
     * @return array<int, string>
     */
    private static function contractItems(int|string|null $jobId): array
    {
        if ($jobId === null || ! modules()->enabled('construction_contracts')) {
            return [];
        }

        $contracts = DB::table('construction_contracts')->where('job_id', $jobId)->pluck('id');

        if ($contracts->isEmpty()) {
            return [];
        }

        return DB::table('construction_contract_items')
            ->whereIn('contract_id', $contracts)
            ->where('is_active', true)
            ->orderBy('sort')->orderBy('item_no')
            ->limit(500)
            ->get(['id', 'item_no', 'description'])
            ->mapWithKeys(fn ($row): array => [
                $row->id => $row->item_no.' — '.str($row->description)->limit(60),
            ])
            ->all();
    }
}
