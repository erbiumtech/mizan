<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\ProgressClaims\Schemas;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\ProgressClaim;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * A claim's header. The money is on the lines, and the header is stamped from them on submission.
 *
 * The claimed totals are deliberately not editable here: they are the sum of what was measured, and a header that
 * disagreed with its own lines is the first thing a certifier would reject.
 */
class ProgressClaimForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
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
                    ->disabled(fn (?ProgressClaim $record): bool => $record !== null)
                    ->dehydrated(),

                TextInput::make('claim_number')
                    ->label('Number')
                    ->maxLength(255)
                    ->helperText('Left blank, the next in this contract\'s series.'),

                DatePicker::make('period_start')
                    ->label('Period from'),

                DatePicker::make('period_end')
                    ->label('Valuation date')
                    ->required()
                    ->helperText('Every figure on this claim is "to" this date.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
