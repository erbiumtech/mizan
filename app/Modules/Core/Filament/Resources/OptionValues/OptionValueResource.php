<?php

namespace App\Modules\Core\Filament\Resources\OptionValues;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Core\Filament\Resources\OptionValues\Pages\CreateOptionValue;
use App\Modules\Core\Filament\Resources\OptionValues\Pages\EditOptionValue;
use App\Modules\Core\Filament\Resources\OptionValues\Pages\ListOptionValues;
use App\Modules\Core\Models\OptionValue;
use App\Support\OptionLists;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What every admin-managed dropdown in the panel offers, in one screen.
 *
 * One resource rather than one per list: the lists are three fields each, and seven
 * near-identical resources would be seven directories to keep in step. Which lists
 * appear is decided by the modules this company has — a company without Construction
 * QHSE is not offered NCR categories to write — and that filter lives in
 * `getEloquentQuery()` so it holds for the direct URL as well as the sidebar.
 *
 * Reference data, so the form and table live here rather than in their own Schemas/
 * and Tables/ classes; four fields do not earn two more files.
 */
class OptionValueResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = OptionValue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel = 'dropdown option';

    protected static ?string $pluralModelLabel = 'dropdown options';

    // Spelled out rather than derived, because NavigationTree places the item by this
    // exact string and NavigationTreeTest fails the build if the two drift.
    protected static ?string $navigationLabel = 'Dropdown Options';

    protected static ?int $navigationSort = 30;

    /**
     * Administrators, like Company Settings — this is Company Settings, spread over
     * several dropdowns. `moduleIsAvailable()` is called because defining canAccess()
     * here shadows the trait's; Core is locked, so it always answers true.
     */
    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && (auth()->user()?->isAdministrator() ?? false);
    }

    /**
     * Rows of a list this company cannot see are not this company's business to edit.
     * They stay in the table — a licence can come back, and deleting somebody's data
     * because a module was switched off for a month is not a decision this screen gets
     * to make.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('list', array_keys(OptionLists::enabled()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('list')
                ->label('Dropdown')
                ->options(fn (): array => array_map(
                    fn (array $list): string => $list['label'],
                    OptionLists::enabled(),
                ))
                ->required()
                ->native(false)
                ->searchable()
                ->live()
                // Moving an entry between lists would change what an existing column
                // means without touching a single row of it.
                ->disabled(fn (?OptionValue $record): bool => $record !== null)
                ->dehydrated()
                ->helperText(fn ($get): ?string => OptionLists::all()[$get('list')]['help'] ?? null),

            TextInput::make('label')
                ->required()
                ->maxLength(255)
                // The table has a unique index on (list, value), and re-adding an entry
                // that is merely switched off is the obvious thing to try — without this
                // it is a 500 rather than a sentence saying where the entry went.
                ->rule(static function (?OptionValue $record, Get $get): Closure {
                    return static function (string $attribute, $value, Closure $fail) use ($record, $get): void {
                        // Only a new entry mints a value; an edit changes the label alone.
                        if ($record !== null) {
                            return;
                        }

                        $exists = OptionValue::query()
                            ->where('list', $get('list'))
                            ->where('value', $value)
                            ->first();

                        if ($exists !== null) {
                            $fail($exists->is_active
                                ? '"'.$value.'" is already in this list.'
                                : '"'.$value.'" is in this list already, switched off. Switch it back on rather than adding a second one.');
                        }
                    };
                })
                ->helperText('What people see in the dropdown. Renaming one relabels it everywhere; records already saved keep pointing at it.'),

            Toggle::make('is_active')
                ->label('Offered')
                ->default(true)
                ->helperText('Switch off rather than delete: an entry that is no longer offered stays readable on the records that already use it.'),

            TextInput::make('sort')
                ->label('Order in the list')
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('list')
                    ->label('Dropdown')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OptionLists::all()[$state]['label'] ?? $state)
                    ->sortable(),

                TextColumn::make('label')->searchable()->sortable(),

                // What the column actually stores. Only interesting when it differs from
                // the label, which is exactly when somebody needs to see it.
                TextColumn::make('value')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->color('gray'),

                IconColumn::make('is_active')->label('Offered')->boolean()->sortable(),

                TextColumn::make('sort')->label('Order')->alignEnd()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('list')
                    ->label('Dropdown')
                    ->options(fn (): array => array_map(
                        fn (array $list): string => $list['label'],
                        OptionLists::enabled(),
                    )),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('list')->orderBy('sort')->orderBy('label'))
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOptionValues::route('/'),
            'create' => CreateOptionValue::route('/create'),
            'edit' => EditOptionValue::route('/{record}/edit'),
        ];
    }
}
