<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\RelationManagers;

use App\Support\LandlordUserColumn;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Comments';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                // Comments are per-tenant, the author is a landlord user — see
                // LandlordUserColumn.
                TextColumn::make('user.name')
                    ->label('Author')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => LandlordUserColumn::sort($query, $direction, 'name')),

                TextColumn::make('body')
                    ->wrap()
                    ->searchable(),

                IconColumn::make('resolved_at')
                    ->label('Resolved')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
