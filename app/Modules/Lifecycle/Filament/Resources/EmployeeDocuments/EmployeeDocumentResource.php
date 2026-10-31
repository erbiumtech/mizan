<?php

namespace App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages\CreateEmployeeDocument;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages\EditEmployeeDocument;
use App\Modules\Lifecycle\Filament\Resources\EmployeeDocuments\Pages\ListEmployeeDocuments;
use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Support\DocumentRequirements;
use App\Support\EmployeeAccess;
use App\Support\EmployeeOptions;
use App\Support\LandlordUserColumn;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EmployeeDocumentResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = EmployeeDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $modelLabel = 'Employee document';

    protected static ?int $navigationSort = 61;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    /** Expired, or lapsing inside the tightest warning threshold. */
    /** The query behind the badge. Batched with every other one on the page — see NavigationBadge. */
    public static function badgeQuery(): ?Builder
    {
        return static::getEloquentQuery()
            ->expiring()
            ->whereDate('expires_on', '<=', now()->addDays(30)->toDateString());
    }

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label(__('Employee'))
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

            Select::make('kind')
                ->options(array_combine(EmployeeDocument::KINDS, array_map('ucfirst', EmployeeDocument::KINDS)))
                ->live()
                ->required(),

            TextInput::make('number')
                ->label(fn (Get $get): string => $get('kind') === EmployeeDocument::KIND_INSURANCE ? 'Policy number' : 'Number')
                ->maxLength(255),

            // A policy's own three facts — docs/bexio-gap-plan.md Phase 5, item 19. Its term and its scan
            // are the fields every document has; its expiry is watched like every other document's.
            TextInput::make('insurer')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => $get('kind') === EmployeeDocument::KIND_INSURANCE),

            TextInput::make('cover_amount')
                ->label(__('Sum insured'))
                ->numeric()
                ->minValue(0)
                ->visible(fn (Get $get): bool => $get('kind') === EmployeeDocument::KIND_INSURANCE),

            TextInput::make('premium')
                ->label(__('Annual premium'))
                ->numeric()
                ->minValue(0)
                ->visible(fn (Get $get): bool => $get('kind') === EmployeeDocument::KIND_INSURANCE)
                ->helperText('What the company pays, in the base currency.'),

            DatePicker::make('issued_on')
                ->label(fn (Get $get): string => $get('kind') === EmployeeDocument::KIND_INSURANCE ? 'Cover from' : 'Issued on')
                ->native(false),

            DatePicker::make('expires_on')
                ->native(false)
                ->helperText('Leave blank for a document that never lapses — a degree, a CNIC copy. Warnings are sent once at 60, 30 and 7 days, not every day.'),

            FileUpload::make('file_path')
                ->label(__('Scan'))
                ->disk('public')
                ->directory('employee-documents')
                // What the company accepts, not what the framework can take —
                // docs/saas-launch-plan.md §14.8. A company that only wants PDFs says so
                // under Company Settings and this follows, with no deployment.
                ->acceptedFileTypes(fn (): array => array_map(
                    fn (string $format): string => ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$format],
                    DocumentRequirements::formats(),
                ))
                ->maxSize(fn (): int => DocumentRequirements::maxKb())
                ->helperText(fn (): string => 'Among the most sensitive records here. Served only to somebody signed in '
                    .'with access to this company. '.strtoupper(implode(', ', DocumentRequirements::formats()))
                    .', up to '.number_format(DocumentRequirements::maxKb() / 1024, 1).' MB.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label(__('Employee'))
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('kind')->badge()->color('gray')->sortable(),

                TextColumn::make('number')->placeholder('—')->searchable()->toggleable(),

                TextColumn::make('insurer')->placeholder('—')->toggleable(),

                TextColumn::make('expires_on')
                    ->label(__('Expires'))
                    ->date('d M Y')
                    ->placeholder('never')
                    // "Expired 40 days ago" and "expires in 40 days" are different
                    // problems, and the colour has to say which.
                    ->color(fn (EmployeeDocument $record): string => match (true) {
                        $record->expires_on === null => 'gray',
                        $record->hasExpired() => 'danger',
                        ($record->daysUntilExpiry() ?? 999) <= 30 => 'warning',
                        default => 'gray',
                    })
                    ->description(function (EmployeeDocument $record): ?string {
                        $days = $record->daysUntilExpiry();

                        return match (true) {
                            $days === null => null,
                            $days < 0 => 'lapsed '.abs($days).' day(s) ago',
                            $days <= 60 => "in {$days} day(s)",
                            default => null,
                        };
                    })
                    ->sortable(),

                // The queue — §14.8. Three states rather than a boolean: a document
                // nobody has looked at is the company's job, and one that was refused is
                // the employee's, and a tick cannot say which.
                TextColumn::make('review')
                    ->label(__('Review'))
                    ->badge()
                    ->state(fn (EmployeeDocument $record): string => match (true) {
                        $record->isVerified() => 'Verified',
                        $record->isRejected() => 'Refused',
                        default => 'Awaiting review',
                    })
                    ->color(fn (EmployeeDocument $record): string => match (true) {
                        $record->isVerified() => 'success',
                        $record->isRejected() => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (EmployeeDocument $record): ?string => $record->rejection_reason),
            ])
            ->defaultSort('expires_on')
            ->filters([
                SelectFilter::make('kind')
                    ->options(array_combine(EmployeeDocument::KINDS, array_map('ucfirst', EmployeeDocument::KINDS))),

                Filter::make('expiring')
                    ->label(__('Lapsing or lapsed'))
                    ->query(fn (Builder $query): Builder => $query->expiring()
                        ->whereDate('expires_on', '<=', now()->addDays(60)->toDateString())),

                Filter::make('awaiting')
                    ->label(__('Awaiting review'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('verified_at')->whereNull('rejected_at')),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label(__('Verify'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Says that somebody has looked at the scan and it is what it claims to be.')
                    ->visible(fn (EmployeeDocument $record): bool => auth()->user()?->can('verify', $record) ?? false)
                    ->action(function (EmployeeDocument $record): void {
                        $record->update(['verified_at' => now(), 'verified_by' => auth()->id(), 'rejected_at' => null, 'rejection_reason' => null, 'rejected_by' => null]);
                        Notification::make()->success()->title('Verified.')->send();
                    }),

                Action::make('reject')
                    ->label(__('Refuse'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (EmployeeDocument $record): bool => auth()->user()?->can('verify', $record) ?? false)
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Why'))
                            ->required()
                            ->rows(2)
                            ->helperText('The employee is told this, so write what they have to do: "the back of the '
                                .'card is missing", "the scan is unreadable".'),
                    ])
                    ->action(function (EmployeeDocument $record, array $data): void {
                        $record->update(['rejected_at' => now(), 'rejected_by' => auth()->id(), 'rejection_reason' => $data['reason'], 'verified_at' => null, 'verified_by' => null]);
                        Notification::make()->warning()->title('Refused, with your reason on the document.')->send();
                    }),

                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeDocuments::route('/'),
            'create' => CreateEmployeeDocument::route('/create'),
            'edit' => EditEmployeeDocument::route('/{record}/edit'),
        ];
    }
}
