<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ComplianceDocuments\Schemas;

use App\Modules\ConstructionContracts\Models\ComplianceDocument;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\Invoicing\Models\Contact;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * One compliance document.
 *
 * **There is no status field**, and that is the design rather than an omission: §12 makes the status computed from these
 * dates, because a saved status stops agreeing with them the moment a policy lapses — and then the screen says
 * everything is fine while a subcontractor works with no cover.
 *
 * **Scope decides what the document has to cover.** A public liability policy is `company` scope and covers everything;
 * a lien waiver is `period` scope and covers one payment. Filing a waiver as company scope would let one signature clear
 * every payment for the life of the contract, which is the mistake this field exists to prevent.
 */
class ComplianceDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('contact_id')
                    ->label('Subcontractor')
                    ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    // Absent without Invoicing, which owns Contacts (§18.1). The register still works; the column
                    // stays null and the document belongs to its contract.
                    ->visible(fn (): bool => modules()->enabled('invoicing'))
                    ->helperText('Whose document it is. Company-scope documents cover every contract they hold.'),

                Select::make('contract_id')
                    ->label('Contract')
                    ->options(fn (): array => Contract::query()->payable()->with('job')->get()
                        ->mapWithKeys(fn (Contract $c): array => [
                            $c->getKey() => "{$c->job?->code} · {$c->contract_number} — {$c->title}",
                        ])
                        ->all())
                    ->searchable()
                    ->placeholder('Company-wide')
                    ->helperText('Leave blank for an obligation that is not specific to one contract.'),

                Select::make('kind')
                    ->label('What it is')
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
                    ->required(),

                Select::make('scope')
                    ->label('Scope')
                    ->options([
                        ComplianceDocument::SCOPE_COMPANY => 'Company — covers everything they do',
                        ComplianceDocument::SCOPE_CONTRACT => 'Contract — this subcontract only',
                        ComplianceDocument::SCOPE_PERIOD => 'Period — one payment or one policy period',
                    ])
                    ->default(ComplianceDocument::SCOPE_COMPANY)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->helperText('A lien waiver is per period. Filing one as company scope would clear every payment on the contract.'),

                TextInput::make('reference')
                    ->label('Policy or certificate number')
                    ->maxLength(255),

                TextInput::make('issuer')
                    ->label('Issued by')
                    ->maxLength(255)
                    ->helperText('The insurer, the authority, the bank.'),

                DatePicker::make('effective_from')
                    ->label('Valid from'),

                DatePicker::make('expires_on')
                    ->label('Expires')
                    ->helperText('Left blank means it does not expire — a trade licence with no end date is not expiring.'),

                DatePicker::make('period_start')
                    ->label('Period from')
                    ->visible(fn (callable $get): bool => $get('scope') === ComplianceDocument::SCOPE_PERIOD),

                DatePicker::make('period_end')
                    ->label('Period to')
                    ->visible(fn (callable $get): bool => $get('scope') === ComplianceDocument::SCOPE_PERIOD),

                TextInput::make('amount_covered')
                    ->label('Sum insured')
                    ->numeric()
                    ->helperText('Checked against any minimum the requirement sets — a token policy does not satisfy one.'),

                DatePicker::make('received_on')
                    ->label('Received')
                    ->helperText('Arriving and being read are two different facts. Verifying is the action on the register.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
