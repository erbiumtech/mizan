<?php

namespace App\Modules\Core\Filament\Platform\Resources\SitePages;

use App\Modules\Core\Filament\Platform\Resources\SitePages\Pages\CreateSitePage;
use App\Modules\Core\Filament\Platform\Resources\SitePages\Pages\EditSitePage;
use App\Modules\Core\Filament\Platform\Resources\SitePages\Pages\ListSitePages;
use App\Modules\Core\Models\SitePage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Hand-written public pages — terms, privacy, about — docs/company-website-plan.md W1.
 *
 * Landlord-backed, as everything on this panel must be (PlatformPanelIsLandlordOnlyTest). Markdown
 * in, Markdown stored, HTML only at render time; a draft is invisible to the public side.
 */
class SitePageResource extends Resource
{
    protected static ?string $model = SitePage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'page';

    protected static ?string $slug = 'site-pages';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->components([
                TextInput::make('title')
                    ->label(__('Title'))
                    ->required()
                    ->maxLength(160)
                    // The address follows the title until somebody types one, so the field validation
                    // below sees the slug that will actually be saved, never a blank the model fills later.
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if (blank($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->label(__('Address'))
                    ->prefix(url('/p').'/')
                    ->helperText(__('Made from the title until you type one. Letters, numbers and dashes.'))
                    ->required()
                    ->maxLength(80)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('slug', Str::slug((string) $state)))
                    ->dehydrateStateUsing(fn (?string $state): string => Str::slug((string) $state))
                    ->unique(ignoreRecord: true),
                TextInput::make('meta_description')
                    ->label(__('Search description'))
                    ->helperText(__('One sentence for search engines; the first paragraph is used when blank.'))
                    ->maxLength(160),
                Textarea::make('body')
                    ->label(__('Body'))
                    ->helperText(__('Markdown. Headings, lists, links and emphasis; HTML is stripped.'))
                    ->rows(18)
                    ->required(),
                Toggle::make('is_published')->label(__('Published')),
                TextInput::make('sort')->label(__('Order in lists'))->numeric()->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label(__('Title'))->searchable()->sortable(),
                TextColumn::make('slug')->label(__('Address'))->prefix('/p/')->searchable(),
                IconColumn::make('is_published')->label(__('Published'))->boolean(),
                TextColumn::make('published_at')->label(__('Published on'))->date()->sortable()->toggleable(),
                TextColumn::make('updated_at')->label(__('Updated'))->since()->sortable(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                Action::make('view')
                    ->label(__('Open'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (SitePage $record): string => $record->url(), shouldOpenInNewTab: true)
                    ->visible(fn (SitePage $record): bool => $record->is_published),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSitePages::route('/'),
            'create' => CreateSitePage::route('/create'),
            'edit' => EditSitePage::route('/{record}/edit'),
        ];
    }
}
