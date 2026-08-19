<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\Trades\Schemas;

use App\Modules\Construction\Models\CostCode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TradeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->maxLength(32)
                    ->helperText('Short, and yours: STF, MASON, CARP. This is what appears on a site sheet.'),

                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('description')
                    ->maxLength(255)
                    ->columnSpanFull(),

                Select::make('default_cost_code_id')
                    ->label('Usual cost code')
                    ->options(fn (): array => CostCode::query()
                        ->where('is_leaf', true)->where('is_active', true)
                        ->orderBy('code')->get()
                        ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => "{$code->code} — {$code->name}"])
                        ->all())
                    ->searchable()
                    // A default, not a rule: one trade works to several codes on a job of any size, and the labour
                    // record's own code is the authority.
                    ->helperText('Filled in for you on a site sheet, and changeable there. Leave blank if you code labour by activity rather than by trade.'),

                Toggle::make('is_active')
                    ->label('In use')
                    ->default(true)
                    // Not a delete: the rates hanging off a trade are the history of what it cost, and §2.2 says that
                    // history is what prices the next tender.
                    ->helperText('Switching a trade off takes it out of the pickers and leaves its rate history alone. There is no delete for that reason.'),

                TextInput::make('sort')
                    ->numeric()
                    ->default(0)
                    ->helperText('Order in the pickers.'),
            ]);
    }
}
