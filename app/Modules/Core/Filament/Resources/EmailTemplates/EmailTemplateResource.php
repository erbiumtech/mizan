<?php

namespace App\Modules\Core\Filament\Resources\EmailTemplates;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Core\Filament\Resources\EmailTemplates\Pages\CreateEmailTemplate;
use App\Modules\Core\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Modules\Core\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Modules\Core\Filament\Resources\EmailTemplates\Schemas\EmailTemplateForm;
use App\Modules\Core\Filament\Resources\EmailTemplates\Tables\EmailTemplatesTable;
use App\Modules\Core\Models\EmailTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The wording of the emails this company sends.
 *
 * Each template overrides one notification. Anything left empty keeps the wording the
 * application ships with, so a company that only wants to change a subject line
 * changes a subject line.
 */
class EmailTemplateResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = EmailTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'key';

    protected static ?string $modelLabel = 'Email wording';

    protected static ?string $pluralModelLabel = 'Email wording';

    public static function form(Schema $schema): Schema
    {
        return EmailTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmailTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailTemplates::route('/'),
            'create' => CreateEmailTemplate::route('/create'),
            'edit' => EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}
