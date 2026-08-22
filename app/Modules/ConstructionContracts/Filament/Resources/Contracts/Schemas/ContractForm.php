<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Contracts\Schemas;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Support\ContractVocabulary;
use App\Modules\Invoicing\Models\Contact;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The contract, in the order somebody fills it in.
 *
 * **The standard and the measurement basis are two questions, and the form asks both.** §8.1: "AIA contracts
 * are routinely unit-price, and FIDIC Yellow is lump sum. It is the measurement basis, never the standard,
 * that decides whether quantities are remeasured." A form that derived one from the other would be wrong on
 * ordinary contracts and give no way to say so.
 *
 * The dates section carries the neutral three — practical completion, the defects period in days, final
 * completion — labelled in the vocabulary of the chosen standard. The expiry of the defects period is
 * **not** a field, because it is computed: a stored expiry stops agreeing with a completion date somebody
 * corrected last week (§8.1).
 */
class ContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The contract')
                    ->columns(2)
                    ->schema([
                        Select::make('job_id')
                            ->label('Job')
                            ->options(fn (): array => Job::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            // Moving a contract to another job would take its schedule, its variations and any
                            // certificate issued against it somewhere none of them belong.
                            ->disabled(fn (?Contract $record): bool => $record !== null)
                            ->dehydrated(),

                        TextInput::make('contract_number')
                            // Deliberately not required: blank means "the next number in this job's series",
                            // which is what `ContractService` fills in. A required field here would make the
                            // series something everyone types by hand, and a gap in a contract series is
                            // something somebody has to explain later.
                            ->maxLength(255)
                            ->helperText('Left blank, the next number in the job\'s series.'),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('side')
                            ->label('Side')
                            ->options([
                                Contract::SIDE_RECEIVABLE => 'Receivable — we bill the employer',
                                Contract::SIDE_PAYABLE => 'Payable — we pay a subcontractor',
                            ])
                            ->default(Contract::SIDE_RECEIVABLE)
                            ->selectablePlaceholder(false)
                            ->disabled(fn (?Contract $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('contact_id')
                            ->label('Other party')
                            ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            // Absent without Invoicing, which owns Contacts — §18.1's guarded coupling. The
                            // column stays null and the contract is still a contract.
                            ->visible(fn (): bool => modules()->enabled('invoicing'))
                            ->helperText('The employer, or the subcontractor. Nullable until award.'),

                        Select::make('contract_standard')
                            ->label('Contract family')
                            ->options(ContractVocabulary::options())
                            ->default(ContractVocabulary::FIDIC)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->helperText('Drives the vocabulary, the numbering series and the printed form. Frozen once anything is certified.'),

                        Select::make('fidic_book')
                            ->label('FIDIC book')
                            ->options([
                                'red' => 'Red — building and engineering works designed by the employer',
                                'yellow' => 'Yellow — plant and design-build',
                                'silver' => 'Silver — EPC turnkey',
                                'green' => 'Green — short form',
                                'gold' => 'Gold — design, build and operate',
                                'pink' => 'Pink — MDB harmonised',
                            ])
                            ->visible(fn (callable $get): bool => $get('contract_standard') === ContractVocabulary::FIDIC)
                            ->helperText('Printed clause references, and nothing else.'),

                        Select::make('measurement_basis')
                            ->label('Measurement basis')
                            ->options([
                                Contract::BASIS_LUMP_SUM => 'Lump sum',
                                Contract::BASIS_REMEASURED => 'Remeasured',
                                Contract::BASIS_MIXED => 'Mixed',
                                Contract::BASIS_COST_PLUS => 'Cost plus',
                                Contract::BASIS_TARGET_COST => 'Target cost',
                            ])
                            ->default(Contract::BASIS_LUMP_SUM)
                            ->selectablePlaceholder(false)
                            // Asked separately from the standard on purpose — see the class docblock.
                            ->helperText('Whether quantities are remeasured. Not a consequence of the contract family.'),

                        Select::make('parent_contract_id')
                            ->label('Under head contract')
                            ->options(fn (?Contract $record): array => Contract::query()
                                ->receivable()
                                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->get()
                                ->mapWithKeys(fn (Contract $c): array => [$c->getKey() => $c->displayName()])
                                ->all())
                            ->searchable()
                            ->visible(fn (callable $get): bool => $get('side') === Contract::SIDE_PAYABLE)
                            ->helperText('What makes back-to-back retention reportable.'),

                        Textarea::make('scope_summary')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Money')
                    ->columns(3)
                    ->schema([
                        TextInput::make('contract_sum')
                            ->numeric()
                            ->required()
                            ->helperText('Original. The revised sum is computed from agreed variations.'),

                        TextInput::make('currency_code')
                            ->label('Currency')
                            ->maxLength(3),

                        TextInput::make('minimum_certificate_amount')
                            ->label('Minimum certificate')
                            ->numeric()
                            ->helperText('Below this, the value rolls into the next certificate.'),

                        TextInput::make('retention_percent')
                            ->label('Retention %')
                            ->numeric(),

                        TextInput::make('retention_limit_percent')
                            ->label('Retention limit %')
                            ->numeric()
                            ->helperText('Of the contract sum. Retention stops accruing here.'),

                        TextInput::make('retention_limit_amount')
                            ->label('Retention limit')
                            ->numeric()
                            // Both exist because contracts say it both ways, and converting one into the other
                            // would stop tracking a percentage cap when the sum moves on a remeasured job.
                            ->helperText('In money, where the contract states a figure instead.'),

                        Select::make('retention_release_rule')
                            ->label('Release rule')
                            ->options([
                                Contract::RELEASE_FIDIC_TWO_STAGE => 'Two stage — half at taking-over, half at the end of the defects period',
                                Contract::RELEASE_AIA_SUBSTANTIAL => 'At substantial completion, less a punch-list holdback',
                                Contract::RELEASE_SINGLE_STAGE => 'Single stage',
                                Contract::RELEASE_CUSTOM => 'Bespoke stages',
                            ])
                            ->selectablePlaceholder(false)
                            ->helperText('Seeded from the contract family, and negotiable — a FIDIC contract with a single-stage release is ordinary.'),

                        TextInput::make('retention_first_release_pct')
                            ->label('First release %')
                            ->numeric()
                            ->helperText('Of the balance held. 50 under FIDIC.'),

                        TextInput::make('materials_retention_percent')
                            ->label('Materials retention %')
                            ->numeric()
                            ->helperText('Materials on site are often retained at a different rate, or not at all.'),

                        TextInput::make('advance_payment_amount')
                            ->label('Advance payment')
                            ->numeric(),

                        TextInput::make('advance_recovery_start_pct')
                            ->label('Recovery starts at %')
                            ->numeric()
                            ->helperText('Of the contract sum certified.'),

                        TextInput::make('advance_recovery_rate_pct')
                            ->label('Recovery rate %')
                            ->numeric()
                            ->helperText('Of each certificate, until repaid.'),

                        TextInput::make('liquidated_damages_per_day')
                            ->label('Damages per day')
                            ->numeric(),

                        TextInput::make('liquidated_damages_cap_pct')
                            ->label('Damages cap %')
                            ->numeric(),
                    ]),

                Section::make('Time and payment')
                    ->columns(3)
                    ->schema([
                        DatePicker::make('contract_date'),
                        DatePicker::make('commencement_date'),

                        TextInput::make('time_for_completion_days')
                            ->label('Time for completion (days)')
                            ->numeric(),

                        DatePicker::make('contract_completion_date')
                            ->label('Completion date'),

                        DatePicker::make('extended_completion_date')
                            ->label('Extended completion')
                            ->helperText('Moves only through an approved extension of time.'),

                        DatePicker::make('practical_completion_date')
                            // Labelled in the standard's own words: taking-over, substantial completion or
                            // practical completion are one event with three names (§8).
                            ->label(fn (callable $get): string => ContractVocabulary::for(
                                $get('contract_standard') ?: ContractVocabulary::FIDIC
                            )->completionEvent())
                            ->helperText('Triggers the first retention release.'),

                        TextInput::make('defects_period_days')
                            ->label(fn (callable $get): string => ContractVocabulary::for(
                                $get('contract_standard') ?: ContractVocabulary::FIDIC
                            )->defectsPeriod().' (days)')
                            ->numeric()
                            ->helperText('Its expiry is computed from the completion date above, never stored.'),

                        DatePicker::make('final_completion_date'),

                        TextInput::make('payment_terms_days')
                            ->label('Payment terms (days)')
                            ->numeric()
                            ->helperText("FIDIC's 56. Statute, not code — so it lives here."),

                        TextInput::make('certification_period_days')
                            ->label('Certification period (days)')
                            ->numeric()
                            ->helperText("FIDIC's 28."),
                    ]),
            ]);
    }
}
