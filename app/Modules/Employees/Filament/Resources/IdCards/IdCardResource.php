<?php

namespace App\Modules\Employees\Filament\Resources\IdCards;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Employees\Filament\Resources\IdCards\Pages\ListIdCards;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeIdCard;
use App\Modules\Employees\Services\IdCards;
use App\Support\CompanyDates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Staff ID cards — docs/saas-launch-plan.md §14.10.
 *
 * Issuing records a card; printing renders one. They are separate because a card is a
 * thing in the world: it is issued once, it can be lost, and which serial is live has
 * to survive somebody reprinting the artwork.
 */
class IdCardResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = EmployeeIdCard::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'ID Cards';

    protected static ?string $modelLabel = 'ID card';

    protected static ?int $navigationSort = 62;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('employee.user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serial')->label(__('Card no.'))->searchable()->sortable(),
                TextColumn::make('employee.display_label')->label(__('Employee'))->searchable(),
                TextColumn::make('issued_on')->label(__('Issued'))->date('d M Y')->sortable(),
                TextColumn::make('expires_on')
                    ->label(__('Expires'))
                    ->date('d M Y')
                    ->placeholder('never')
                    ->sortable(),
                TextColumn::make('state')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (EmployeeIdCard $record): string => $record->state())
                    ->color(fn (string $state): string => match ($state) {
                        'Valid' => 'success',
                        'Expired' => 'warning',
                        default => 'danger',
                    })
                    ->description(fn (EmployeeIdCard $record): ?string => $record->revoked_reason),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Filter::make('live')
                    ->label(__('Still valid'))
                    ->query(fn (Builder $query): Builder => $query->live()
                        ->where(fn (Builder $q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()->toDateString()))),
            ])
            ->recordActions([
                Action::make('print')
                    ->label(__('Print'))
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(function (EmployeeIdCard $record) {
                        $pdf = app(IdCards::class)->render($record);

                        return response()->streamDownload(fn () => print $pdf->raw(), $pdf->getName(), ['Content-Type' => 'application/pdf']);
                    }),

                Action::make('revoke')
                    ->label(__('Cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (EmployeeIdCard $record): bool => ! $record->isRevoked()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->modalDescription('The card stops verifying immediately. Whoever is holding it keeps holding '
                        .'it, which is why the reason matters: the verify page shows it.')
                    ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)->placeholder('Lost on site')])
                    ->action(function (EmployeeIdCard $record, array $data): void {
                        app(IdCards::class)->revoke($record, $data['reason']);
                        Notification::make()->warning()->title('Cancelled.')->send();
                    }),
            ])
            ->headerActions([
                Action::make('issue')
                    ->label(__('Issue a card'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => auth()->user()?->can('create', EmployeeIdCard::class) ?? false)
                    ->modalHeading('Issue an ID card')
                    ->modalDescription('Whatever card that person is holding is cancelled. One live card each, so '
                        .'a lost card stops verifying the moment its replacement exists.')
                    ->schema([
                        Select::make('employee_id')
                            ->label(__('Employee'))
                            ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')
                                ->orderBy('employee_id')->get()
                                ->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                            ->searchable()
                            ->required(),
                        DatePicker::make('expires_on')
                            ->label(__('Valid to'))
                            ->native(false)
                            ->default(fn (): string => app(IdCards::class)->expiryFor())
                            ->helperText('Leave blank for a card that does not expire. Three years is the default.'),
                    ])
                    ->action(function (array $data) {
                        $employee = Employee::findOrFail($data['employee_id']);
                        $card = app(IdCards::class)->issue($employee, $data['expires_on'] ?? null);

                        Notification::make()->success()
                            ->title('Issued card '.$card->serial)
                            ->body($card->expires_on ? 'Valid to '.CompanyDates::write($card->expires_on).'.' : 'It does not expire.')
                            ->send();

                        $pdf = app(IdCards::class)->render($card);

                        return response()->streamDownload(fn () => print $pdf->raw(), $pdf->getName(), ['Content-Type' => 'application/pdf']);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // A sheet, because these are printed on an office printer and cut with
                    // scissors — see IdCards::sheet().
                    BulkAction::make('sheet')
                        ->label(__('Print a sheet'))
                        ->icon('heroicon-o-printer')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $pdf = app(IdCards::class)->sheet($records);

                            return response()->streamDownload(fn () => print $pdf->raw(), $pdf->getName(), ['Content-Type' => 'application/pdf']);
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListIdCards::route('/')];
    }

    public static function canCreate(): bool
    {
        // Issued through the header action, which cancels the previous card in the same
        // breath. A plain create form would let somebody write a second live one.
        return false;
    }
}
