<?php

namespace App\Modules\Employees\Filament\Resources\LetterRequests;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Employees\Filament\Resources\LetterRequests\Pages\CreateLetterRequest;
use App\Modules\Employees\Filament\Resources\LetterRequests\Pages\ListLetterRequests;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\LetterRequest;
use App\Modules\Employees\Models\LetterTemplate;
use App\Modules\Employees\Services\TemplatedLetters;
use App\Notifications\DocumentIssued;
use App\Support\EmployeeAccess;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Actions\Action;
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
use Throwable;
use UnitEnum;

/** The HR letter queue — docs/saas-launch-plan.md §14.7: an employee asks, HR issues or refuses. */
class LetterRequestResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = LetterRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Letter Requests';

    protected static ?string $modelLabel = 'Letter request';

    protected static ?int $navigationSort = 55;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.user']);

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class, fn (): int => static::getEloquentQuery()->pending()->count());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')->label('Employee')
                // Own record and downline, unless privileged: a picker of every colleague let anyone file in another's name.
                ->options(fn (): array => app(EmployeeAccess::class)->scopeAccessibleEmployees(Employee::query()->where('is_active', true), auth()->user())->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()->required()
                ->default(fn (): ?int => app(EmployeeAccess::class)->ownEmployeeId(auth()->user())),
            Select::make('kind')->label('Letter')
                ->options(fn (): array => array_diff_key(LetterTemplate::KINDS, array_flip(LetterTemplate::EVENT_KINDS)))
                ->required()
                ->helperText('Increment, promotion, transfer and confirmation letters arrive on their own when the thing happens.'),
            TextInput::make('purpose')->maxLength(190)->placeholder('A visa application; opening a bank account'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Asked')->date('d M Y')->sortable(),
                TextColumn::make('employee.display_label')->label('Employee'),
                TextColumn::make('kind')->label('Letter')->formatStateUsing(fn (string $state): string => LetterTemplate::KINDS[$state] ?? $state),
                TextColumn::make('purpose')->limit(40)->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'issued' => 'success', 'refused' => 'danger', default => 'warning'
                })
                    ->description(fn (LetterRequest $r): ?string => $r->refusal_reason),
            ])
            ->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'issued' => 'Issued', 'refused' => 'Refused'])])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('issue')
                    ->label('Issue')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('The letter is rendered from the template and emailed to the employee.')
                    ->visible(fn (LetterRequest $r): bool => $r->isPending() && (auth()->user()?->can('LetterIssue') ?? false))
                    ->action(function (LetterRequest $record): void {
                        try {
                            $pdf = app(TemplatedLetters::class)->renderPdf($record->employee, $record->kind, ['purpose' => (string) $record->purpose]);
                            $record->employee?->user?->notify(new DocumentIssued($record->kindLabel(), $pdf->getName(), $pdf->raw()));
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        $record->update(['status' => LetterRequest::STATUS_ISSUED, 'decided_by' => auth()->id(), 'decided_at' => now()]);
                        Notification::make()->success()->title('Issued and emailed.')->send();
                    }),
                Action::make('refuse')
                    ->label('Refuse')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LetterRequest $r): bool => $r->isPending() && (auth()->user()?->can('LetterIssue') ?? false))
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(function (LetterRequest $record, array $data): void {
                        $record->update(['status' => LetterRequest::STATUS_REFUSED, 'decided_by' => auth()->id(), 'decided_at' => now(), 'refusal_reason' => $data['reason']]);
                        Notification::make()->warning()->title('Refused, with your reason on the request.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLetterRequests::route('/'),
            'create' => CreateLetterRequest::route('/create'),
        ];
    }
}
