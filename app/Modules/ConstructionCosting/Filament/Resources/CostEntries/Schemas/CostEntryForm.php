<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Schemas;

use App\Modules\Construction\Models\CostCode;
use App\Modules\Construction\Models\Job;
use App\Modules\Construction\Models\WbsNode;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\ConstructionCosting\Models\CostEntry;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * What one cost is.
 *
 * Two fields are deliberately absent, and both would be bugs if present. **The posting period** is derived from
 * `incurred_on` by `CostLedger`, never chosen — a caller that could pick its own period could put cost into a
 * closed month, which is the one thing that service exists to prevent. And **`cost_type`** is snapshotted off
 * the code rather than offered, because a cost whose type disagreed with its code would break every report that
 * groups by one and joins the other.
 */
class CostEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What and where')
                    ->columns(2)
                    ->schema([
                        Select::make('job_id')
                            ->label(__('Job'))
                            ->options(fn (): array => Job::query()->live()->orderBy('code')->get()
                                ->mapWithKeys(fn (Job $job): array => [$job->getKey() => "{$job->code} — {$job->name}"])
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),

                        Select::make('cost_code_id')
                            ->label(__('Cost code'))
                            // Only bookable codes: a heading would double-count in every rolled-up total, and
                            // a switched-off code is one the company retired on purpose.
                            ->options(fn (): array => CostCode::query()->bookable()->orderBy('code')->get()
                                ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => $code->label()])
                                ->all())
                            ->searchable()
                            ->required()
                            ->helperText('Only bookable codes appear — a heading cannot carry cost.'),

                        Select::make('wbs_node_id')
                            ->label(__('Work breakdown'))
                            // Scoped to the chosen job: a node from another job's breakdown would file this
                            // cost against scope it has nothing to do with.
                            ->options(fn (Get $get): array => $get('job_id')
                                ? WbsNode::query()->where('job_id', $get('job_id'))->orderBy('code')->get()
                                    ->mapWithKeys(fn (WbsNode $node): array => [$node->getKey() => $node->label()])
                                    ->all()
                                : [])
                            ->searchable()
                            ->helperText('Optional. The intersection of this and the cost code is what carries a budget.'),

                        Select::make('kind')
                            // A reversal is written by the ledger and is never chosen, but it can be opened to give
                            // it a posting rule: offered as itself and fixed, or its edit failed "kind is invalid".
                            ->options(fn (?CostEntry $record): array => [
                                CostEntry::KIND_ACTUAL => 'Actual',
                                CostEntry::KIND_ACCRUAL => 'Accrual',
                                CostEntry::KIND_ALLOCATION => 'Allocation',
                                CostEntry::KIND_RECLASS => 'Reclassification',
                            ] + ($record?->kind === CostEntry::KIND_REVERSAL ? [CostEntry::KIND_REVERSAL => 'Reversal'] : []))
                            ->default(CostEntry::KIND_ACTUAL)
                            ->disabled(fn (?CostEntry $record): bool => $record?->kind === CostEntry::KIND_REVERSAL)
                            ->dehydrated(fn (?CostEntry $record): bool => $record?->kind !== CostEntry::KIND_REVERSAL)
                            ->selectablePlaceholder(false)
                            ->native(false),
                    ]),

                Section::make('How much')
                    ->description('The unit rate is the whole of cost control and cannot be got from money alone, so record the quantity wherever there is one.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->helperText('Negative for a credit. One signed figure, never a debit and a credit.'),

                        TextInput::make('quantity')
                            ->numeric()
                            ->helperText('12.5 tonnes, 90 m³.'),

                        TextInput::make('unit_of_measure')
                            ->label(__('Unit'))
                            ->maxLength(16),

                        DatePicker::make('incurred_on')
                            ->label(__('Incurred on'))
                            ->native(false)
                            ->default(now())
                            ->required()
                            ->helperText('The real date. If its month is closed the cost lands in the open one and is flagged as late — the closed month keeps the total its certificate was built on.'),

                        TextInput::make('reference')
                            ->maxLength(255)
                            ->helperText('An invoice or docket number.'),

                        TextInput::make('description')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),

                Section::make('The general ledger')
                    ->description('Which relationship this cost has to the books. Never inferred from whether a journal entry exists.')
                    ->columns(2)
                    ->schema([
                        Select::make('gl_treatment')
                            ->label(__('Treatment'))
                            ->options([
                                CostEntry::GL_PENDING => 'Pending — should post and has not yet',
                                CostEntry::GL_MIRRORED => 'Mirrored — the ledger posting is the source',
                                CostEntry::GL_POSTED => 'Posted — this is the source and it has posted',
                                CostEntry::GL_MEMO => 'Memo — deliberately never posts',
                            ])
                            // The company's own default for a hand-typed cost (Company Settings → Construction costing).
                            ->default(fn (): string => (string) setting('construction.costing.manual_gl_treatment', CostEntry::GL_PENDING))
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live()
                            ->helperText('Memo is for burden at a rate or a notional comparison: a real cost to the job that is not a ledger event.'),

                        /*
                         * Which credit the period's posting owes for this cost. Without it a pending manual entry
                         * matched no posting rule, could never reach the ledger, and held up the month's close until
                         * somebody forced it. Labour and burden are left optional because the posting infers them
                         * (site wages payable, burden absorbed); everything else has to say.
                         */
                        Select::make('gl_purpose')
                            ->label(__('Posting rule'))
                            ->options(ControlAccount::PURPOSES)
                            ->placeholder('Choose the account this cost credits')
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('gl_treatment') === CostEntry::GL_PENDING)
                            ->required(fn (Get $get): bool => $get('gl_treatment') === CostEntry::GL_PENDING
                                && ! static::postingInfersPurpose($get('cost_code_id'), (bool) $get('is_burden')))
                            ->helperText('The control account the period\'s posting credits for this cost — the job-cost account '
                                .'is the debit. Nominate the accounts under Control accounts.'),

                        Toggle::make('is_burden')
                            ->label(__('Is burden'))
                            ->helperText('Charged at a rate and absorbed elsewhere. Reconciliation reports the gap if it is charged and never absorbed.'),
                    ]),
            ]);
    }

    /** Whether the GL posting can tell the rule on its own: burden, or a labour-type code (site wages payable). */
    private static function postingInfersPurpose(mixed $costCodeId, bool $isBurden): bool
    {
        if ($isBurden) {
            return true;
        }

        return filled($costCodeId) && CostCode::query()->whereKey($costCodeId)->value('cost_type') === CostCode::TYPE_LABOUR;
    }
}
