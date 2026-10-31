<?php

namespace App\Modules\Projects\Filament\Resources\Projects;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Projects\Filament\Resources\Projects\Pages\CreateProject;
use App\Modules\Projects\Filament\Resources\Projects\Pages\EditProject;
use App\Modules\Projects\Filament\Resources\Projects\Pages\ListProjects;
use App\Modules\Projects\Filament\Resources\Projects\Pages\ViewProject;
use App\Modules\Projects\Filament\Resources\Projects\RelationManagers\EmployeesRelationManager;
use App\Modules\Projects\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Modules\Projects\Filament\Resources\Projects\Schemas\ProjectInfolist;
use App\Modules\Projects\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Modules\Projects\Models\Project;
use App\Support\ResourceContributions;
use BackedEnum;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProjectResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Project::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::SheetView;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        // Credentials are deliberately absent: searching by one is meaningless
        // and would surface secrets in command-palette subtitles.
        return ['code', 'name'];
    }

    /**
     * No row scoping: every project is visible to everyone holding ProjectView.
     * The .user eager loads matter because an employee's display name comes
     * from the linked user record; health state lives on the environment row,
     * so it needs no extra query.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('employees')
            ->with([
                'environments',
                'manager.user',
                'secondaryManager.user',
                'customFieldValues.customField',
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    /**
     * The same infolist as the view page, here too so the list's view sheet shows it rather than a
     * disabled form — docs/side-sheets.md.
     */
    public static function infolist(Schema $schema): Schema
    {
        return ProjectInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EmployeesRelationManager::class,
            ...ResourceContributions::relationManagersFor(static::class),
        ];
    }

    /**
     * The project page's tabs — docs/project-management-plan.md §7.
     *
     * Board, Backlog, Sprints, Epics and Releases are the work module's, and `work` requires
     * `projects`, so they arrive through the slot rather than by name: this file never learns what
     * fills it. **Empty when nothing has been contributed**, deliberately — a company without the
     * work module sees the project page exactly as it was, rather than a two-tab bar reading
     * "Overview · Edit" over a page that used to have neither.
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        $record = method_exists($page, 'getRecord') ? $page->getRecord() : null;

        if (! $record instanceof Project) {
            return [];
        }

        $contributed = ResourceContributions::subNavigationFor(static::class, $record);

        if ($contributed === []) {
            return [];
        }

        return [
            ...$page->generateNavigationItems([ViewProject::class, EditProject::class]),
            ...$contributed,
        ];
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
