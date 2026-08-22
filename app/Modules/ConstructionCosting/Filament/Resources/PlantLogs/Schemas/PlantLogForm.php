<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Schemas;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionCosting\Models\PlantItem;
use App\Modules\ConstructionCosting\Models\Worker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A machine's day, in three kinds of unit.
 *
 * **The three unit fields are the point of the form.** Working, idle and standby are separate rates in every hire
 * agreement, and one blended figure would lose the breakdown that answers "what did we pay for a crane to stand still".
 */
class PlantLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The day')
                    ->columns(2)
                    ->schema([
                        Select::make('plant_item_id')
                            ->label('Machine')
                            ->options(fn (): array => PlantItem::query()->active()->orderBy('code')->get()
                                ->mapWithKeys(fn (PlantItem $item): array => [$item->getKey() => $item->displayName()])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),

                        DatePicker::make('logged_on')
                            ->label('Date')
                            ->native(false)
                            ->required()
                            ->default(now()),

                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),

                        Select::make('cost_code_id')
                            ->label('Cost code')
                            ->options(fn (): array => CostCode::query()
                                ->where('is_leaf', true)->where('is_active', true)
                                ->orderBy('code')->get()
                                ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                                ->all())
                            ->searchable()
                            ->required(),

                        Select::make('wbs_node_id')
                            ->label('WBS element')
                            ->options(fn (callable $get): array => $get('job_id') === null ? [] : WbsNode::query()
                                ->where('job_id', $get('job_id'))
                                ->orderBy('path')->get()
                                ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => "{$node->code} — {$node->name}"])
                                ->all())
                            ->searchable()
                            ->placeholder('Whole job'),

                        Select::make('operator_worker_id')
                            ->label('Operator')
                            ->options(fn (): array => Worker::query()->active()->orderBy('code')->get()
                                ->mapWithKeys(fn (Worker $worker): array => [$worker->getKey() => $worker->displayName()])
                                ->all())
                            ->searchable()
                            // Ours, not the supplier's. On hired-with-operator plant this stays blank because the
                            // operator's time is inside the hire rate and this company never costs it.
                            ->helperText('Your operator, where you supply one. Their own time is a separate site sheet.')
                            ->placeholder('None, or the supplier\'s'),
                    ]),

                Section::make('Units')
                    ->description('How the day divides. A machine that worked six hours and stood for two is six working and two idle — not eight of anything.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('working_units')
                            ->label('Working')
                            ->numeric()
                            ->default(8)
                            ->required(),

                        TextInput::make('idle_units')
                            ->label('Idle')
                            ->numeric()
                            ->default(0)
                            ->helperText('On site, available, not working.'),

                        TextInput::make('standby_units')
                            ->label('Standby')
                            ->numeric()
                            ->default(0),

                        Placeholder::make('rates')
                            ->label('What this machine charges')
                            ->content(fn (callable $get): string => static::rates($get))
                            ->columnSpanFull(),
                    ]),

                Section::make('Meter and fuel')
                    ->description('Evidence rather than the basis of the charge: engine hours legitimately differ from charged hours, so a mismatch is not refused. A reading that went backwards is.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('meter_start')
                            ->label('Meter at start')
                            ->numeric(),

                        TextInput::make('meter_end')
                            ->label('Meter at end')
                            ->numeric(),

                        TextInput::make('fuel_quantity')
                            ->label('Fuel issued')
                            ->numeric()
                            ->helperText('Litres issued to this machine today.'),
                    ]),

                Section::make('Notes')
                    ->columns(2)
                    ->schema([
                        TextInput::make('downtime_reason')
                            ->label('Why it stood')
                            ->maxLength(255)
                            // Idle time with no reason is idle time nobody will ever explain, and standing plant is
                            // among the most recoverable costs on a job.
                            ->helperText('Breakdown, no operator, waiting on another trade, weather. Worth filling in: standing plant is often somebody else\'s cost.'),

                        TextInput::make('description')
                            ->maxLength(255)
                            ->helperText('What it was doing. Appears on the cost entry for an owned machine.'),

                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The machine's rates, and — for hired plant — the fact that this log will not book cost.
     *
     * Said on the form rather than discovered afterwards, because "I logged it and the job shows nothing" is the
     * question this sentence prevents.
     */
    private static function rates(callable $get): string
    {
        $item = $get('plant_item_id') ? PlantItem::query()->find($get('plant_item_id')) : null;

        if ($item === null) {
            return 'Choose a machine.';
        }

        if (! $item->hasChargeableRate()) {
            return 'This machine has no rate set, so a log against it cannot be approved. Set the working rate on the '
                .'plant record first — a machine charged at nothing makes the job look cheap and the fleet look free.';
        }

        $parts = [];

        foreach ([['working_rate', 'working'], ['idle_rate', 'idle'], ['standby_rate', 'standby']] as [$column, $label]) {
            $parts[] = $label.': '.($item->{$column} === null || (float) $item->{$column} === 0.0
                ? 'not charged'
                : number_format((float) $item->{$column}, 2));
        }

        return implode(', ', $parts).'. '.($item->isOwned()
            ? 'Owned, so approving this log charges the job internal hire.'
            : 'Hired, so approving this log books no cost — the supplier\'s invoice is the cost, and this log is what '
                .'checks it.');
    }
}
