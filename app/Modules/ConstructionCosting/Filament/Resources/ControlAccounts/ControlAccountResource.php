<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Pages\CreateControlAccount;
use App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Pages\EditControlAccount;
use App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Pages\ListControlAccounts;
use App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Schemas\ControlAccountForm;
use App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Tables\ControlAccountsTable;
use App\Modules\ConstructionCosting\Models\ControlAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Which general-ledger accounts §4 is about — `docs/construction-management-plan.md` §4.2.
 *
 * **The screen that makes the reconciliation a tool rather than a claim.** §4.2 asks for "a table rather than a config
 * list, so a company can name its own accounts and the report can name them back" — and this is where the naming
 * happens. A hard-coded `5020` reconciles the wrong account in every tenant whose chart of accounts somebody else
 * built, and it fails silently: the difference comes out wrong and nothing says which account was read.
 *
 * Two jobs on one screen, which is why the *Purpose* column is next to the *Kind* one:
 *
 *  - **Kind** puts an account in scope of the report. Every `cost` account makes up the GL cost total the difference is
 *    computed from.
 *  - **Purpose** makes an account a posting target. It exists because two accounts of kind `recovery` are
 *    indistinguishable by kind, and the posting service has to find *the* one that absorbs labour burden rather than *a*
 *    candidate. It is unique in the database, so there can never be two.
 *
 * Set up once, at implementation, by whoever owns the chart of accounts — which is why it rides on
 * `ConstructionGlPost` rather than earning its own permission.
 */
class ControlAccountResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ControlAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Construction';

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?int $navigationSort = 58;

    protected static ?string $label = 'Control account';

    protected static ?string $pluralLabel = 'Control accounts';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('account');
    }

    public static function form(Schema $schema): Schema
    {
        return ControlAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ControlAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListControlAccounts::route('/'),
            'create' => CreateControlAccount::route('/create'),
            'edit' => EditControlAccount::route('/{record}/edit'),
        ];
    }
}
