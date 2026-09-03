<?php

namespace App\Modules\Support\Filament\Resources\TicketCategories;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Support\Filament\Resources\TicketCategories\Pages\CreateTicketCategory;
use App\Modules\Support\Filament\Resources\TicketCategories\Pages\EditTicketCategory;
use App\Modules\Support\Filament\Resources\TicketCategories\Pages\ListTicketCategories;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketCategory;
use BackedEnum;
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

/**
 * The kinds of ticket this company takes, and what it has committed to for each.
 *
 * **The screen this table never had.** `TicketCategory` shipped with a model, a policy, its permissions and
 * a dropdown on every ticket — and nothing to edit it with, so a company was stuck with whatever categories
 * its database happened to hold and no way to add the one it needed.
 *
 * Reference data, so the form and table live here rather than in their own Schemas/ and Tables/ classes.
 */
class TicketCategoryResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = TicketCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'ticket category';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('Unique: two categories meaning the same thing split their own SLA report in half.'),

            Select::make('default_priority')
                ->label('Priority a new ticket starts at')
                ->options(array_combine(Ticket::PRIORITIES, array_map('ucfirst', Ticket::PRIORITIES)))
                ->default('normal')
                ->selectablePlaceholder(false)
                ->native(false)
                ->required()
                ->helperText('Whoever raises the ticket can still change it.'),

            TextInput::make('sla_response_minutes')
                ->label('Respond within (minutes)')
                ->numeric()
                ->minValue(1)
                ->helperText('Leave blank for no commitment. The clock is MEASURED, never enforced — nothing is blocked or escalated by it.'),

            TextInput::make('sla_resolution_minutes')
                ->label('Resolve within (minutes)')
                ->numeric()
                ->minValue(1)
                ->helperText('Leave blank for no commitment. Blank is not a breach; a zero would read as instantly overdue.'),

            Toggle::make('is_active')
                ->label('Offered on new tickets')
                ->default(true)
                ->helperText('Switch off rather than delete: a category with tickets against it keeps their history and their SLA figures.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('default_priority')->label('Starts at')->badge()->sortable(),

                TextColumn::make('sla_response_minutes')
                    ->label('Respond in')
                    ->placeholder('no commitment')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : $state.' min'),

                TextColumn::make('sla_resolution_minutes')
                    ->label('Resolve in')
                    ->placeholder('no commitment')
                    ->alignEnd()
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : $state.' min'),

                TextColumn::make('tickets_count')->label('Tickets')->counts('tickets')->alignEnd()->sortable(),

                IconColumn::make('is_active')->label('Offered')->boolean()->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTicketCategories::route('/'),
            'create' => CreateTicketCategory::route('/create'),
            'edit' => EditTicketCategory::route('/{record}/edit'),
        ];
    }
}
