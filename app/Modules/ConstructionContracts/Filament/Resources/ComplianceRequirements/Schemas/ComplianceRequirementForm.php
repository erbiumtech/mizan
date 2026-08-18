<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceRequirements\Schemas;

use App\Modules\ConstructionContracts\Models\ComplianceRequirement;
use App\Modules\ConstructionContracts\Models\Contract;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * One requirement.
 *
 * **`blocks` defaults to certification**, which is §12's argument rather than a convenience: blocking at *payment*
 * leaves an approved payable in the ledger that finance cannot pay, and that is worse than a refusal because the
 * liability already exists and the stuck payment has nobody's name on it.
 *
 * **Grace days are worth setting deliberately.** A renewal in the post is ordinary, and a register that stopped a
 * certificate on the day a policy lapsed would be overridden every month until somebody set the grace period they
 * should have set at the start — and an override that happens every month is not a control.
 */
class ComplianceRequirementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('contract_id')
                    ->label('Contract')
                    ->options(fn (): array => Contract::query()->payable()->with('job')->get()
                        ->mapWithKeys(fn (Contract $c): array => [
                            $c->getKey() => "{$c->job?->code} · {$c->contract_number} — {$c->title}",
                        ])
                        ->all())
                    ->searchable()
                    ->placeholder('Every subcontract (company template)')
                    ->helperText('Blank makes this a company template. A contract with its own requirement of the same kind overrides the template.'),

                Select::make('kind')
                    ->label('What is required')
                    ->options([
                        'general_liability' => 'General / public liability insurance',
                        'workers_compensation' => "Workers' compensation",
                        'professional_indemnity' => 'Professional indemnity',
                        'contract_works' => 'Contract works insurance',
                        'lien_waiver_conditional_progress' => 'Lien waiver — conditional, progress',
                        'lien_waiver_unconditional_progress' => 'Lien waiver — unconditional, progress',
                        'lien_waiver_conditional_final' => 'Lien waiver — conditional, final',
                        'lien_waiver_unconditional_final' => 'Lien waiver — unconditional, final',
                        'certified_payroll' => 'Certified payroll',
                        'prequalification' => 'Prequalification',
                        'trade_licence' => 'Trade licence',
                        'tax_registration' => 'Tax registration',
                        'bond' => 'Bond',
                        'safety_plan' => 'Safety plan',
                        'method_statement' => 'Method statement',
                        'training_matrix' => 'Training matrix',
                    ])
                    ->searchable()
                    ->required()
                    // One per kind per contract: two would each carry their own `blocks` and nobody could say which
                    // applied.
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, callable $get) => $rule
                        ->where('contract_id', $get('contract_id'))),

                Select::make('blocks')
                    ->label('What its absence stops')
                    ->options([
                        ComplianceRequirement::BLOCKS_CERTIFICATION => 'Certification — the certificate will not issue',
                        ComplianceRequirement::BLOCKS_NONE => 'Nothing — chased, but never stops a payment',
                        ComplianceRequirement::BLOCKS_PAYMENT => 'Payment',
                        ComplianceRequirement::BLOCKS_BOTH => 'Both',
                    ])
                    ->default(ComplianceRequirement::BLOCKS_CERTIFICATION)
                    ->selectablePlaceholder(false)
                    ->helperText('Certification is the useful one: refusing to certify is a sentence somebody can act on, where a payable finance cannot pay is a stuck payment with no owner.'),

                TextInput::make('grace_days')
                    ->label('Grace days past expiry')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->helperText('A renewal in the post is ordinary. Zero here means a certificate stops on the day the policy lapses.'),

                TextInput::make('minimum_cover')
                    ->label('Minimum sum insured')
                    ->numeric()
                    ->helperText('Where the contract specifies one, so a token policy does not satisfy it. Blank means any policy of that kind will do.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
