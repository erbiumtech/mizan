<?php

namespace App\Modules\Lifecycle\Filament\Resources\EmploymentContracts;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Resources\EmploymentContracts\Pages\CreateEmploymentContract;
use App\Modules\Lifecycle\Filament\Resources\EmploymentContracts\Pages\EditEmploymentContract;
use App\Modules\Lifecycle\Filament\Resources\EmploymentContracts\Pages\ListEmploymentContracts;
use App\Modules\Lifecycle\Models\EmploymentContract;
use App\Modules\Lifecycle\Services\EmploymentContracts;
use App\Support\EmployeeAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
use InvalidArgumentException;
use UnitEnum;

/** Employment contracts — docs/saas-launch-plan.md §14.5. */
class EmploymentContractResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = EmploymentContract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $navigationLabel = 'Employment Contracts';

    protected static ?string $modelLabel = 'Employment contract';

    protected static ?int $navigationSort = 52;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.user', 'signedDocument']);

        // An employee sees their own contracts; everybody else needs the document permission.
        if (! (auth()->user()?->can('EmployeeDocumentView') ?? false)) {
            $query->whereHas('employee', fn ($q) => $q->where('user_id', auth()->id()));
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return (auth()->user()?->can('EmployeeDocumentView') ?? false) || app(EmployeeAccess::class)->isEmployee(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')->label(__('Employee'))
                ->options(fn (): array => Employee::query()->where('is_active', true)->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                ->searchable()->required()->disabledOn('edit'),
            TextInput::make('number')->maxLength(40)->placeholder(fn (): string => EmploymentContract::nextNumber())->helperText('Left blank, the next number is taken.'),
            Select::make('type')->options(fn (?EmploymentContract $record): array => options('lifecycle.employment_contract_type', $record?->type))->default('permanent')->required(),
            DatePicker::make('starts_on')->native(false)->required(),
            DatePicker::make('ends_on')->native(false)->nullable()->afterOrEqual('starts_on')->helperText('Blank for open-ended. A dated contract reaches the expiring-documents report once signed.'),
            TextInput::make('gross')->label(__('Monthly gross'))->numeric()->minValue(0)->required(),
            TextInput::make('probation_months')->numeric()->minValue(0)->maxValue(24)->nullable(),
            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('employee.display_label')->label(__('Employee')),
                TextColumn::make('type')->formatStateUsing(fn (string $state): string => options('lifecycle.employment_contract_type', $state)[$state] ?? $state),
                TextColumn::make('starts_on')->date('d M Y'),
                TextColumn::make('ends_on')->date('d M Y')->placeholder('open-ended'),
                TextColumn::make('gross')->numeric(0)->alignRight(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'active' => 'success', 'ended' => 'gray', default => 'warning'
                })
                    ->description(fn (EmploymentContract $c): ?string => $c->isAccepted() ? 'accepted '.$c->accepted_at->format('d M Y H:i').($c->accepted_ip ? ' from '.$c->accepted_ip : '') : null),
                TextColumn::make('signedDocument.file_path')->label(__('Signed scan'))->formatStateUsing(fn ($state): string => $state ? 'attached' : '')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'ended' => 'Ended'])])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('download')->label(__('PDF'))->icon('heroicon-o-arrow-down-tray')
                    ->action(function (EmploymentContract $record) {
                        $pdf = app(EmploymentContracts::class)->renderPdf($record);

                        return response()->streamDownload(fn () => print ($pdf->raw()), $pdf->getName(), ['Content-Type' => 'application/pdf']);
                    }),
                Action::make('activate')->label(__('Activate'))->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
                    ->visible(fn (EmploymentContract $c): bool => $c->status === EmploymentContract::STATUS_DRAFT && (auth()->user()?->can('update', $c) ?? false))
                    ->action(function (EmploymentContract $record): void {
                        app(EmploymentContracts::class)->activate($record);
                        Notification::make()->success()->title('Active. Any earlier contract has ended.')->send();
                    }),
                Action::make('accept')->label(__('Accept'))->icon('heroicon-o-pencil-square')->color('primary')
                    ->visible(fn (EmploymentContract $c): bool => $c->isActive() && ! $c->isAccepted() && (int) $c->employee?->user_id === (int) auth()->id())
                    ->modalHeading('Accept this contract')
                    ->modalDescription('Your acceptance is recorded with the time and the address it was made from. It is your agreement to the contract as written; the signed copy, if the company asks for one, is attached separately.')
                    ->schema([
                        TagsInput::make('witnesses')->label(__('Witnesses'))->placeholder('Add a name')->helperText('Optional.'),
                        Checkbox::make('ip_disclosure')->label(__('I understand my network address is recorded with this acceptance'))->accepted(),
                    ])
                    ->action(function (EmploymentContract $record, array $data): void {
                        try {
                            app(EmploymentContracts::class)->accept($record, auth()->user(), request()->ip(), (array) ($data['witnesses'] ?? []), (bool) ($data['ip_disclosure'] ?? false));
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Accepted.')->send();
                    }),
                Action::make('attachScan')->label(__('Signed scan'))->icon('heroicon-o-paper-clip')->color('gray')
                    ->visible(fn (EmploymentContract $c): bool => $c->isActive() && $c->signed_document_id === null && (auth()->user()?->can('update', $c) ?? false))
                    ->schema([FileUpload::make('file_path')->label(__('Scan'))->disk('public')->directory('employee-documents')->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(10240)->required()])
                    ->action(function (EmploymentContract $record, array $data): void {
                        app(EmploymentContracts::class)->attachSignedScan($record, $data['file_path']);
                        Notification::make()->success()->title('Attached as an employee document.')->send();
                    }),
                Action::make('end')->label(__('End'))->icon('heroicon-o-stop-circle')->color('danger')->requiresConfirmation()
                    ->visible(fn (EmploymentContract $c): bool => $c->isActive() && (auth()->user()?->can('update', $c) ?? false))
                    ->schema([DatePicker::make('on')->label(__('Ended on'))->native(false)->default(now())->required()])
                    ->action(function (EmploymentContract $record, array $data): void {
                        app(EmploymentContracts::class)->end($record, $data['on']);
                        Notification::make()->success()->title('Ended.')->send();
                    }),
                EditAction::make()->visible(fn (EmploymentContract $c): bool => $c->status === EmploymentContract::STATUS_DRAFT),
                DeleteAction::make()->visible(fn (EmploymentContract $c): bool => $c->status === EmploymentContract::STATUS_DRAFT),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmploymentContracts::route('/'),
            'create' => CreateEmploymentContract::route('/create'),
            'edit' => EditEmploymentContract::route('/{record}/edit'),
        ];
    }
}
