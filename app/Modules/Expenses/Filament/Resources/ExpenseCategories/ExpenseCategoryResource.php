<?php

namespace App\Modules\Expenses\Filament\Resources\ExpenseCategories;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Expenses\Filament\Resources\ExpenseCategories\Pages\CreateExpenseCategory;
use App\Modules\Expenses\Filament\Resources\ExpenseCategories\Pages\EditExpenseCategory;
use App\Modules\Expenses\Filament\Resources\ExpenseCategories\Pages\ListExpenseCategories;
use App\Modules\Expenses\Models\ExpenseCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Expense categories — docs/saas-launch-plan.md §14.3. */
class ExpenseCategoryResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ExpenseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Expense Categories';

    protected static ?int $navigationSort = 33;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->unique(ignoreRecord: true),
            Select::make('transaction_type_id')
                ->label(__('Posts as'))
                ->options(fn (): array => TransactionType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->helperText('The transaction type whose account the line books to. The ledger does not move; the category is a name for it.'),
            Toggle::make('requires_receipt')->label(__('Needs a receipt')),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('transactionType.name')->label(__('Posts as'))->placeholder('—'),
                IconColumn::make('requires_receipt')->boolean()->label(__('Receipt')),
                IconColumn::make('is_active')->boolean()->label(__('Active')),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseCategories::route('/'),
            'create' => CreateExpenseCategory::route('/create'),
            'edit' => EditExpenseCategory::route('/{record}/edit'),
        ];
    }
}
