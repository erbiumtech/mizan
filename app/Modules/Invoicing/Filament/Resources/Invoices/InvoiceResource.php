<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Modules\Invoicing\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Modules\Invoicing\Filament\Resources\Invoices\RelationManagers\HistoryRelationManager;
use App\Modules\Invoicing\Filament\Resources\Invoices\RelationManagers\LinesRelationManager;
use App\Modules\Invoicing\Filament\Resources\Invoices\Schemas\InvoiceForm;
use App\Modules\Invoicing\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Modules\Invoicing\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Modules\Invoicing\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class InvoiceResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Invoice::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing & Inventory';

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['invoice_number'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customFieldValues.customField');
    }

    public static function form(Schema $schema): Schema
    {
        return InvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            // The invoice, read: the list's rows open it and a new invoice lands on it (CreateInvoice).
            'view' => ViewInvoice::route('/{record}'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }
}
