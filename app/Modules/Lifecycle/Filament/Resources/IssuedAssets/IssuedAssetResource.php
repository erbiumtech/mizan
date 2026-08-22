<?php

namespace App\Modules\Lifecycle\Filament\Resources\IssuedAssets;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Lifecycle\Filament\Resources\IssuedAssets\Pages\CreateIssuedAsset;
use App\Modules\Lifecycle\Filament\Resources\IssuedAssets\Pages\EditIssuedAsset;
use App\Modules\Lifecycle\Filament\Resources\IssuedAssets\Pages\ListIssuedAssets;
use App\Modules\Lifecycle\Models\IssuedAsset;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use App\Support\LandlordUserColumn;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Kit somebody was given, and whether it came back.
 *
 * What has not come back is what a final settlement charges for, which is the reason
 * `value` is worth filling in even for a phone that was never capitalised.
 */
class IssuedAssetResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = IssuedAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $modelLabel = 'Issued asset';

    protected static ?int $navigationSort = 62;

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
                ->label('Issued to')
                ->relationship('employee', 'employee_id', fn ($query) => app(EmployeeAccess::class)
                    ->scopeAccessibleEmployees($query->with('user'), auth()->user()))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search(
                    $search,
                    EmployeeOptions::accessibleScope(),
                ))
                ->preload()
                ->required(),

            Select::make('asset_kind')
                ->label('Kind')
                ->options(array_combine(IssuedAsset::KINDS, array_map(
                    fn (string $kind): string => ucfirst(str_replace('_', ' ', $kind)),
                    IssuedAsset::KINDS,
                )))
                ->required(),

            TextInput::make('description')->required()->maxLength(255),
            TextInput::make('serial_no')->label('Serial number')->maxLength(255),

            DatePicker::make('issued_on')->native(false)->default(now())->required(),

            TextInput::make('value')
                ->numeric()
                ->helperText('What it would cost if it does not come back. This is the figure a final settlement charges.'),

            // Guarded: a company that keeps its books elsewhere has no fixed asset
            // register to point at, and the field is absent rather than empty.
            Select::make('fixed_asset_id')
                ->label('On the books as')
                ->options(fn (): array => FixedAsset::orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (): bool => modules()->enabled('accounting'))
                ->helperText('Optional. A phone that was never capitalised has a description and no link.'),

            Textarea::make('condition_note')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label('Issued to')
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('description')->searchable()
                    ->description(fn (IssuedAsset $record): ?string => $record->serial_no),

                TextColumn::make('asset_kind')->label('Kind')->badge()->color('gray'),

                TextColumn::make('issued_on')->date('d M Y')->sortable(),

                TextColumn::make('returned_on')
                    ->label('Returned')
                    ->date('d M Y')
                    ->placeholder('still out')
                    ->color(fn (IssuedAsset $record): string => $record->isReturned() ? 'success' : 'warning')
                    ->sortable(),

                TextColumn::make('value')->money('PKR')->placeholder('—')->alignEnd()->toggleable(),
            ])
            ->defaultSort('issued_on', 'desc')
            ->filters([
                Filter::make('outstanding')
                    ->label('Still out')
                    ->query(fn (Builder $query): Builder => $query->outstanding()),
            ])
            ->recordActions([
                Action::make('return')
                    ->label('Mark returned')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (IssuedAsset $record): bool => ! $record->isReturned()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->schema([
                        DatePicker::make('returned_on')->native(false)->default(now())->required(),
                        Textarea::make('condition_note')->label('Condition')->rows(2),
                    ])
                    ->action(function (IssuedAsset $record, array $data): void {
                        $record->update([
                            'returned_on' => $data['returned_on'],
                            'condition_note' => $data['condition_note'] ?? $record->condition_note,
                        ]);

                        Notification::make()->success()
                            ->title('Returned — it will no longer be charged on a final settlement.')
                            ->send();
                    }),

                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIssuedAssets::route('/'),
            'create' => CreateIssuedAsset::route('/create'),
            'edit' => EditIssuedAsset::route('/{record}/edit'),
        ];
    }
}
