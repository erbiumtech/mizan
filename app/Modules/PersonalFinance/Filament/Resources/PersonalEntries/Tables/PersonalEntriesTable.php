<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalEntries\Tables;

use App\Modules\PersonalFinance\Models\PersonalAccount;
use App\Modules\PersonalFinance\Models\PersonalEntry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PersonalEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lines.account'))
            ->columns([
                TextColumn::make('date')->label('Date')->date('j M Y')->sortable(),

                TextColumn::make('description')->label('Description')->searchable()->wrap(),

                // Which category it hit, read off the lines. An expense debits
                // its category; income credits it.
                TextColumn::make('category')
                    ->label('Category')
                    ->state(function (PersonalEntry $record): string {
                        $line = $record->lines->first(fn ($line) => in_array(
                            $line->account?->type,
                            [PersonalAccount::TYPE_INCOME, PersonalAccount::TYPE_EXPENSE],
                            true,
                        ));

                        return $line?->account?->name ?? 'Transfer';
                    })
                    ->badge()
                    ->color(fn (string $state) => $state === 'Transfer' ? 'gray' : 'info'),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('PKR')
                    ->state(fn (PersonalEntry $record) => $record->totalDebit())
                    ->alignEnd()
                    ->weight('semibold'),

                TextColumn::make('lines_summary')
                    ->label('Movement')
                    ->state(function (PersonalEntry $record): string {
                        $from = $record->lines->firstWhere(fn ($line) => (float) $line->credit > 0);
                        $to = $record->lines->firstWhere(fn ($line) => (float) $line->debit > 0);

                        return ($from?->account?->name ?? '?').' → '.($to?->account?->name ?? '?');
                    })
                    ->color('gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('fiscal_year_id')
                    ->label('Tax year')
                    ->relationship('fiscalYear', 'name'),

                Filter::make('date')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('date', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('date', '<=', $date))),
            ])
            ->recordActions([
                // No edit: an entry is two balanced lines, and an edit form that
                // rewrites both without breaking the balance is more machinery
                // than a personal ledger needs. Delete and record it again.
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('Nothing recorded yet')
            ->emptyStateDescription('Use Record income or Record expense above to start keeping your books.');
    }
}
