<?php

namespace App\Modules\Invoicing\Filament\Resources\InvoiceLines;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Invoicing\Filament\Resources\InvoiceLines\Pages\CreateInvoiceLine;
use App\Modules\Invoicing\Filament\Resources\InvoiceLines\Pages\EditInvoiceLine;
use App\Modules\Invoicing\Filament\Resources\InvoiceLines\Pages\ListInvoiceLines;
use App\Modules\Invoicing\Filament\Resources\InvoiceLines\Schemas\InvoiceLineForm;
use App\Modules\Invoicing\Filament\Resources\InvoiceLines\Tables\InvoiceLinesTable;
use App\Modules\Invoicing\Models\InvoiceLine;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class InvoiceLineResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = InvoiceLine::class;

    protected static string|UnitEnum|null $navigationGroup = 'Invoicing & Inventory';

    protected static ?string $recordTitleAttribute = 'description';

    protected static bool $shouldRegisterNavigation = false;

    public static function getGloballySearchableAttributes(): array
    {
        return ['description'];
    }

    public static function form(Schema $schema): Schema
    {
        return InvoiceLineForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoiceLinesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceLines::route('/'),
            'create' => CreateInvoiceLine::route('/create'),
            'edit' => EditInvoiceLine::route('/{record}/edit'),
        ];
    }

    // Nova authorizedToCreate(): can 'InvoiceUpdate'
    public static function canCreate(): bool
    {
        return auth()->user()?->can('InvoiceUpdate') ?? false;
    }

    // Nova authorizedToUpdate(): invoice isDraft() AND can 'InvoiceUpdate'
    public static function canEdit(Model $record): bool
    {
        return (bool) ($record->invoice?->isDraft())
            && (auth()->user()?->can('InvoiceUpdate') ?? false);
    }

    // Nova authorizedToDelete(): same as update
    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->can('InvoiceUpdate') ?? false;
    }
}
