<?php

namespace App\Modules\Core\Filament\Resources\Comments;

use App\Filament\Support\RecordSheet;
use App\Modules\Core\Filament\Resources\Comments\Pages\CreateComment;
use App\Modules\Core\Filament\Resources\Comments\Pages\EditComment;
use App\Modules\Core\Filament\Resources\Comments\Pages\ListComments;
use App\Modules\Core\Filament\Resources\Comments\Schemas\CommentForm;
use App\Modules\Core\Filament\Resources\Comments\Tables\CommentsTable;
use App\Modules\Core\Models\Comment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Audit & Taxes';

    protected static ?string $recordTitleAttribute = 'body';

    // Nova: $displayInNavigation = false
    protected static bool $shouldRegisterNavigation = false;

    /**
     * A comment is made on the thing it is about — a payslip, from its own page — and this form has no way to
     * say what that is. Created from here it had no subject, and the insert failed on the NOT NULL
     * commentable columns: a "New comment" button that could never succeed.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['body'];
    }

    public static function form(Schema $schema): Schema
    {
        return CommentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComments::route('/'),
            'create' => CreateComment::route('/create'),
            'edit' => EditComment::route('/{record}/edit'),
        ];
    }
}
