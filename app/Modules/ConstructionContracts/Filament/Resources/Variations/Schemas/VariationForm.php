<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Variations\Schemas;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\Variation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A variation, in the order it actually happens: instructed, described, justified, then priced.
 *
 * **`is_price_provisional` is not on this form**, and that is deliberate. It is set by approving in principle and
 * cleared by approving — the two actions on the register — because a checkbox that moves money between "certified"
 * and "forecast" is a checkbox somebody ticks by accident. §9's rule is enforced by the service and expressed on
 * screen as two different buttons.
 */
class VariationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What changed')
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
                            // A variation belongs to the contract whose schedule it changes; moving it would
                            // leave its incorporated lines on a schedule it no longer names.
                            ->disabled(fn (?Variation $record): bool => $record !== null)
                            ->dehydrated()
                            ->helperText('Only executed contracts. A draft schedule is edited directly.'),

                        TextInput::make('variation_number')
                            ->label('Number')
                            // Blank means the next in the contract's series, which must have no gaps.
                            ->maxLength(255)
                            ->helperText('Left blank, the next number in this contract\'s series.'),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('origin')
                            ->label('Origin')
                            ->options([
                                'employer_instruction' => 'Employer instruction',
                                'engineer_instruction' => 'Engineer instruction',
                                'architect_supplemental' => 'Architect supplemental instruction',
                                'contractor_proposal' => 'Contractor proposal',
                                'rfi' => 'RFI answer',
                                'ncr' => 'Non-conformance',
                                'site_condition' => 'Site condition',
                                'design_change' => 'Design change',
                                Variation::ORIGIN_PROVISIONAL_SUM => 'Provisional sum expenditure',
                                Variation::ORIGIN_DAYWORKS => 'Dayworks',
                                'claim' => 'Claim',
                                'compensation_event' => 'Compensation event',
                            ])
                            ->default('engineer_instruction')
                            ->selectablePlaceholder(false)
                            ->helperText('Where it came from. What it entitles anybody to depends on this.'),

                        Select::make('valuation_method')
                            ->label('Valued by')
                            ->options([
                                Variation::METHOD_CONTRACT_RATES => 'Rates in the contract',
                                Variation::METHOD_PRO_RATA => 'Pro-rata to contract rates',
                                Variation::METHOD_NEW_RATE => 'A new rate agreed',
                                Variation::METHOD_DAYWORKS => 'Dayworks',
                                Variation::METHOD_LUMP_SUM => 'Lump sum',
                                Variation::METHOD_COST_PLUS => 'Cost plus percentage',
                            ])
                            ->default(Variation::METHOD_CONTRACT_RATES)
                            ->selectablePlaceholder(false)
                            ->helperText('FIDIC 12.3 and 13.3 take these in order: contract rates first, a new rate last.'),

                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),

                        Textarea::make('justification')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Why this is a change rather than included work. Read at adjudication.'),
                    ]),

                Section::make('Money and time')
                    ->columns(3)
                    ->schema([
                        TextInput::make('quoted_amount')
                            ->label('Quoted')
                            ->numeric()
                            ->helperText('What was asked for.'),

                        TextInput::make('assessed_amount')
                            ->label('Assessed')
                            ->numeric()
                            ->helperText('What the certifier decided. Filled in from the lines when priced.'),

                        TextInput::make('approved_amount')
                            ->label('Approved')
                            ->numeric()
                            // Set by approving, and shown here because a negotiated figure is typed before the
                            // button is pressed as often as after.
                            ->helperText('What the parties agreed. This is the figure a certificate may include.'),

                        TextInput::make('time_impact_days')
                            ->label('Time claimed (days)')
                            ->numeric(),

                        TextInput::make('time_granted_days')
                            ->label('Time granted (days)')
                            ->numeric()
                            ->helperText('Money and time are separate awards, and routinely disagree.'),

                        Select::make('eot_status')
                            ->label('Extension of time')
                            ->options([
                                'none' => 'Not claimed',
                                'claimed' => 'Claimed',
                                'granted' => 'Granted',
                                'rejected' => 'Rejected',
                            ])
                            ->default('none')
                            ->selectablePlaceholder(false),

                        DatePicker::make('instructed_on')
                            ->label('Instructed on')
                            ->helperText('The date on the instruction, not the date it was typed here.'),

                        DatePicker::make('submitted_on')
                            ->label('Submitted on')
                            ->helperText('Time bars run from here.'),
                    ]),
            ]);
    }
}
