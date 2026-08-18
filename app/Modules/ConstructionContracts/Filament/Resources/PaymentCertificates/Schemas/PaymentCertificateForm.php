<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\Schemas;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A certificate's header.
 *
 * **None of the money is on this form**, and that is the design: every figure is computed from the lines, the
 * agreed variations and the contract terms, and a typed override would be a certificate whose bottom line nobody
 * can re-derive. What a person chooses here is the period, the claim it answers, and the note.
 *
 * The claim is optional because FIDIC 14.6 lets the Engineer certify without a conforming statement (§10.1).
 */
class PaymentCertificateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The certificate')
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
                            ->live()
                            ->disabled(fn (?PaymentCertificate $record): bool => $record !== null)
                            ->dehydrated(),

                        Select::make('progress_claim_id')
                            ->label('Answers claim')
                            ->options(fn (callable $get): array => ProgressClaim::query()
                                ->where('contract_id', $get('contract_id'))
                                ->orderByDesc('period_end')
                                ->get()
                                ->mapWithKeys(fn (ProgressClaim $c): array => [
                                    $c->getKey() => "{$c->claim_number} — to {$c->period_end?->toDateString()}",
                                ])
                                ->all())
                            ->searchable()
                            ->placeholder('None — certifying without a statement')
                            ->helperText('Optional: clause 14.6 lets the certifier issue without a conforming statement.'),

                        DatePicker::make('period_end')
                            ->label('Valuation date')
                            ->required()
                            ->helperText('Every figure on the certificate is "to" this date.'),

                        DatePicker::make('period_start')
                            ->label('Period from'),

                        Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Printed on the certificate.'),
                    ]),
            ]);
    }
}
