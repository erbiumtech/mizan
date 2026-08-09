<?php

namespace App\Modules\Accounting\Filament\Resources\ScheduledTransactions;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Pages\CreateScheduledTransaction;
use App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Pages\EditScheduledTransaction;
use App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Pages\ListScheduledTransactions;
use App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Schemas\ScheduledTransactionForm;
use App\Modules\Accounting\Filament\Resources\ScheduledTransactions\Tables\ScheduledTransactionsTable;
use App\Modules\Accounting\Models\ScheduledTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ScheduledTransactionResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ScheduledTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Scheduled Entries';

    protected static ?string $modelLabel = 'scheduled entry';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'reference'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('lines');
    }

    public static function form(Schema $schema): Schema
    {
        return ScheduledTransactionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduledTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduledTransactions::route('/'),
            'create' => CreateScheduledTransaction::route('/create'),
            'edit' => EditScheduledTransaction::route('/{record}/edit'),
        ];
    }
}
