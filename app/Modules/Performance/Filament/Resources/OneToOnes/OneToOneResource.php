<?php

namespace App\Modules\Performance\Filament\Resources\OneToOnes;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Filament\Resources\OneToOnes\Pages\CreateOneToOne;
use App\Modules\Performance\Filament\Resources\OneToOnes\Pages\EditOneToOne;
use App\Modules\Performance\Filament\Resources\OneToOnes\Pages\ListOneToOnes;
use App\Modules\Performance\Models\OneToOne;
use App\Support\EmployeeAccess;
use App\Support\LandlordUserColumn;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Recorded one-to-ones.
 *
 * **`private_notes` is never readable by the person it is about**, and that needs its own
 * rule rather than relying on the usual scoping: EmployeeAccess grants a manager their
 * whole DOWNLINE, so without this an employee inside their own manager's scope could read
 * the notes written about them. docs/hrms-plan.md §7.2.
 */
class OneToOneResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = OneToOne::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Performance';

    protected static ?string $modelLabel = 'One-to-one';

    protected static ?int $navigationSort = 13;

    /**
     * Own record and downline.
     *
     * An employee sees the one-to-ones about themselves — that is the point of recording
     * the shared notes — but never the private half, which is enforced per row below and
     * on the model.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('Employee')
                ->options(fn (): array => app(EmployeeAccess::class)
                    ->scopeAccessibleEmployees(Employee::query()->where('is_active', true), auth()->user())
                    ->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                    ->all())
                ->searchable()
                ->required(),

            Select::make('manager_employee_id')
                ->label('Manager')
                ->options(fn (): array => Employee::query()
                    ->where('is_active', true)
                    ->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                    ->all())
                ->searchable()
                ->default(fn (): ?int => Employee::where('user_id', auth()->id())->value('id')),

            DatePicker::make('met_on')->native(false)->default(now())->required(),

            Textarea::make('notes')
                ->label('Notes')
                ->rows(4)
                ->columnSpanFull()
                ->helperText('Shared with the employee.'),

            // Hidden entirely from the person it is about, not merely read-only: the
            // helper text alone would tell them private notes exist about them.
            Textarea::make('private_notes')
                ->label('Private notes')
                ->rows(4)
                ->columnSpanFull()
                ->visible(fn (?OneToOne $record): bool => $record === null
                    || $record->privateNotesVisibleTo(auth()->user()))
                ->helperText('Manager and above only. Never shown to the employee this is about.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label('Employee')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('manager.display_label')->label('Manager')->placeholder('—')->toggleable(),

                TextColumn::make('met_on')->label('Met')->date('d M Y')->sortable(),

                TextColumn::make('notes')->wrap()->limit(80)->placeholder('—'),

                // The presence of private notes is itself withheld from the subject.
                TextColumn::make('private_notes')
                    ->label('Private')
                    ->formatStateUsing(fn ($state): string => $state ? 'yes' : '—')
                    // can(), not hasPermissionTo(): the latter throws for an unseeded
                    // permission name and would take the page down rather than hide a
                    // column. See OneToOne::privateNotesVisibleTo().
                    ->visible(fn (): bool => auth()->user()?->can('ReviewPrivateNotes') ?? false)
                    ->toggleable(),
            ])
            ->defaultSort('met_on', 'desc')
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOneToOnes::route('/'),
            'create' => CreateOneToOne::route('/create'),
            'edit' => EditOneToOne::route('/{record}/edit'),
        ];
    }
}
