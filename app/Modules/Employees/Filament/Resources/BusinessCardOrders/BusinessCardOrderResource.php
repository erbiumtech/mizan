<?php

namespace App\Modules\Employees\Filament\Resources\BusinessCardOrders;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Filament\Support\PendingFirst;
use App\Filament\Support\RecordSheet;
use App\Modules\Employees\Filament\Resources\BusinessCardOrders\Pages\CreateBusinessCardOrder;
use App\Modules\Employees\Filament\Resources\BusinessCardOrders\Pages\ListBusinessCardOrders;
use App\Modules\Employees\Models\BusinessCardOrder;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\BusinessCards;
use App\Support\EmployeeAccess;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Business cards, and the queue they wait in — docs/saas-launch-plan.md §14.10.
 *
 * The same shape as a letter request: asking is not a permission, deciding is. What
 * it adds over an email to the office manager is that the artwork comes out of the
 * record, so forty people's cards carry the company name spelt one way.
 */
class BusinessCardOrderResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = BusinessCardOrder::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Business Cards';

    protected static ?string $modelLabel = 'business card order';

    protected static ?int $navigationSort = 63;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('employee.user');

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    /** The query behind the badge. Batched with every other one on the page — see NavigationBadge. */
    public static function badgeQuery(): ?Builder
    {
        return static::getEloquentQuery()->open();
    }

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label(__('For'))
                // Own record and downline, unless privileged: a picker of every colleague let anyone order in another's name.
                ->options(fn (): array => app(EmployeeAccess::class)->scopeAccessibleEmployees(Employee::query()->where('is_active', true), auth()->user())->with('user')
                    ->orderBy('employee_id')->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()
                ->required()
                ->default(fn (): ?int => app(EmployeeAccess::class)->ownEmployeeId(auth()->user())),

            TextInput::make('quantity')
                ->label(__('How many'))
                ->numeric()
                ->minValue(50)
                ->maxValue(2000)
                ->default(100)
                ->required(),

            // Blank means "what the record says", which is the point of the feature.
            TextInput::make('title_override')
                ->label(__('Title on the card'))
                ->maxLength(190)
                ->placeholder(fn (?BusinessCardOrder $record): string => (string) ($record?->employee?->designation ?: 'As on the employee record'))
                ->helperText('Leave blank to use the designation on the record. Fill it in for a shorter one.'),

            TextInput::make('phone_override')
                ->label(__('Phone on the card'))
                ->tel()
                ->maxLength(40)
                ->helperText('Leave blank to use the number on the record — a direct line, where there is one.'),

            Textarea::make('note')->rows(2)->label(__('Anything for whoever prints them')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('Asked'))->date('d M Y')->sortable(),
                TextColumn::make('employee.display_label')->label(__('For'))->searchable(),
                TextColumn::make('quantity')->alignRight(),
                TextColumn::make('title')->label(__('Title'))->state(fn (BusinessCardOrder $record): string => $record->title() ?: '—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => BusinessCardOrder::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        BusinessCardOrder::STATUS_COLLECTED, BusinessCardOrder::STATUS_PRINTED => 'success',
                        BusinessCardOrder::STATUS_REFUSED => 'danger',
                        BusinessCardOrder::STATUS_APPROVED => 'primary',
                        default => 'warning',
                    })
                    ->description(fn (BusinessCardOrder $record): ?string => $record->refusal_reason),
            ])
            ->defaultSort(PendingFirst::sort(BusinessCardOrder::class))
            ->filters([SelectFilter::make('status')->options(BusinessCardOrder::STATUSES)])
            ->recordActions([
                Action::make('proof')
                    ->label(__('Proof'))
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->action(function (BusinessCardOrder $record) {
                        $pdf = app(BusinessCards::class)->render($record);

                        return response()->streamDownload(fn () => print $pdf->raw(), $pdf->getName(), ['Content-Type' => 'application/pdf']);
                    }),

                Action::make('advance')
                    ->label(fn (BusinessCardOrder $record): string => match ($record->status) {
                        BusinessCardOrder::STATUS_REQUESTED => 'Approve',
                        BusinessCardOrder::STATUS_APPROVED => 'Mark printed',
                        default => 'Mark collected',
                    })
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (BusinessCardOrder $record): bool => $record->status !== BusinessCardOrder::STATUS_COLLECTED
                        && $record->status !== BusinessCardOrder::STATUS_REFUSED
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (BusinessCardOrder $record): void {
                        $record->update([
                            'status' => match ($record->status) {
                                BusinessCardOrder::STATUS_REQUESTED => BusinessCardOrder::STATUS_APPROVED,
                                BusinessCardOrder::STATUS_APPROVED => BusinessCardOrder::STATUS_PRINTED,
                                default => BusinessCardOrder::STATUS_COLLECTED,
                            },
                            'decided_by' => auth()->id(),
                            'decided_at' => now(),
                        ]);

                        Notification::make()->success()->title('Moved on.')->send();
                    }),

                Action::make('refuse')
                    ->label(__('Refuse'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (BusinessCardOrder $record): bool => $record->isOpen()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)])
                    ->action(function (BusinessCardOrder $record, array $data): void {
                        $record->update([
                            'status' => BusinessCardOrder::STATUS_REFUSED,
                            'refusal_reason' => $data['reason'],
                            'decided_by' => auth()->id(),
                            'decided_at' => now(),
                        ]);

                        Notification::make()->warning()->title('Refused, with your reason on the order.')->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('sheet')
                        ->label(__('Artwork for these'))
                        ->icon('heroicon-o-printer')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $pdf = app(BusinessCards::class)->sheet($records);

                            return response()->streamDownload(fn () => print $pdf->raw(), $pdf->getName(), ['Content-Type' => 'application/pdf']);
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBusinessCardOrders::route('/'),
            'create' => CreateBusinessCardOrder::route('/create'),
        ];
    }
}
