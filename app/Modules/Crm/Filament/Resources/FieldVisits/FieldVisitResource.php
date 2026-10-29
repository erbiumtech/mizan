<?php

namespace App\Modules\Crm\Filament\Resources\FieldVisits;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Crm\Filament\Resources\FieldVisits\Pages\CreateFieldVisit;
use App\Modules\Crm\Filament\Resources\FieldVisits\Pages\EditFieldVisit;
use App\Modules\Crm\Filament\Resources\FieldVisits\Pages\ListFieldVisits;
use App\Modules\Crm\Models\FieldVisit;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Services\FieldVisits;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Support\ModuleMap;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/** Visits to leads, deals and customers — docs/saas-launch-plan.md §8.5. */
class FieldVisitResource extends Resource
{
    use BelongsToModule;
    use ScopesToAccessibleEmployees;

    protected static ?string $model = FieldVisit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Field Visits';

    protected static ?string $modelLabel = 'Field visit';

    protected static ?int $navigationSort = 13;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['visitor', 'subject']);

        if (! static::userIsPrivileged()) {
            $query->whereIn('employee_id', static::accessibleEmployeeIds()->all());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('subject_type')
                ->label('Visiting')
                ->options(FieldVisit::subjectTypes())
                ->required()
                ->live()
                ->afterStateUpdated(fn (callable $set) => $set('subject_id', null)),

            Select::make('subject_id')
                ->label('Who')
                ->options(fn (Get $get): array => static::subjectOptions((string) $get('subject_type')))
                ->searchable()
                ->required(),

            Select::make('employee_id')
                ->label('Visitor')
                ->options(fn (): array => Employee::query()->where('is_active', true)->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])->all())
                ->searchable()
                ->required()
                ->default(fn (): ?int => Employee::where('user_id', auth()->id())->value('id')),

            DateTimePicker::make('planned_at')->label('Planned for')->native(false)->seconds(false)->required()->default(now()->addDay()->setTime(10, 0)),

            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    /** @return array<int, string> */
    public static function subjectOptions(string $type): array
    {
        return match ($type) {
            ModuleMap::alias(Lead::class) => Lead::query()->orderBy('company_name')->get()
                ->mapWithKeys(fn (Lead $l): array => [$l->id => trim(($l->company_name ?: '').' '.($l->person_name ? '— '.$l->person_name : ''))])->all(),
            ModuleMap::alias(Opportunity::class) => Opportunity::query()->whereNull('closed_on')->orderBy('title')->pluck('title', 'id')->all(),
            ModuleMap::alias(Contact::class) => modules()->enabled('invoicing') ? Contact::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
            default => [],
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('planned_at')->label('Planned')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('visitor.display_label')->label('Visitor'),
                TextColumn::make('subject')->label('Visiting')->state(fn (FieldVisit $record): string => $record->subjectLabel())
                    ->description(fn (FieldVisit $record): string => FieldVisit::subjectTypes()[$record->subject_type] ?? ''),
                TextColumn::make('checked_in_at')->label('In')->dateTime('H:i')->placeholder('—')
                    ->url(fn (FieldVisit $record): ?string => $record->mapLink(), shouldOpenInNewTab: true)
                    ->description(fn (FieldVisit $record): ?string => $record->mapLink() ? 'map' : null),
                TextColumn::make('checked_out_at')->label('Out')->dateTime('H:i')->placeholder('—'),
                TextColumn::make('outcome')->badge()->formatStateUsing(fn (?string $state): string => FieldVisit::OUTCOMES[$state] ?? '')->placeholder('—'),
                IconColumn::make('confirmed_at')->label('Confirmed')->boolean()->getStateUsing(fn (FieldVisit $record): bool => $record->isConfirmed())
                    ->tooltip(fn (FieldVisit $record): ?string => $record->confirmed_name ? 'by '.$record->confirmed_name : ($record->confirmation_sent_to ? 'sent to '.$record->confirmation_sent_to : null)),
            ])
            ->filters([
                SelectFilter::make('employee_id')->label('Visitor')->relationship('visitor', 'name'),
                Filter::make('unconfirmed')->label('Unconfirmed')->query(fn (Builder $q) => $q->unconfirmed()),
                Filter::make('open')->label('Not yet done')->query(fn (Builder $q) => $q->whereNull('checked_out_at')),
            ])
            ->defaultSort('planned_at', 'desc')
            ->recordActions([
                static::checkInAction(),
                static::checkOutAction(),
                static::resendAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    private static function position(array $data): ?array
    {
        return is_array($data['position'] ?? null) && isset($data['position']['latitude'], $data['position']['longitude'])
            ? $data['position']
            : null;
    }

    public static function checkInAction(): Action
    {
        return Action::make('checkIn')
            ->label('Check in')
            ->icon('heroicon-o-map-pin')
            ->color('success')
            ->visible(fn (FieldVisit $record): bool => ! $record->isCheckedIn() && (auth()->user()?->can('update', $record) ?? false))
            ->schema([ViewField::make('position')->label('Location')->view('filament.forms.geolocation')])
            ->action(function (FieldVisit $record, array $data): void {
                try {
                    app(FieldVisits::class)->checkIn($record, static::position($data));
                    Notification::make()->title('Checked in.')->body(static::position($data) ? 'Location recorded.' : 'No location was recorded.')->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function checkOutAction(): Action
    {
        return Action::make('checkOut')
            ->label('Check out')
            ->icon('heroicon-o-flag')
            ->color('warning')
            ->visible(fn (FieldVisit $record): bool => $record->isCheckedIn() && ! $record->isCheckedOut() && (auth()->user()?->can('update', $record) ?? false))
            ->schema([
                ViewField::make('position')->label('Location')->view('filament.forms.geolocation'),
                Select::make('outcome')->options(FieldVisit::OUTCOMES)->required(),
                Textarea::make('notes')->rows(3),
            ])
            ->action(function (FieldVisit $record, array $data): void {
                try {
                    $visit = app(FieldVisits::class)->checkOut($record, static::position($data), $data['notes'] ?? null, $data['outcome'] ?? null);
                    Notification::make()->title('Checked out.')
                        ->body($visit->confirmation_sent_to ? 'Confirmation link sent to '.$visit->confirmation_sent_to.'.' : 'No confirmation link was sent: no address, or links are switched off.')
                        ->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function resendAction(): Action
    {
        return Action::make('resendConfirmation')
            ->label('Resend link')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->visible(fn (FieldVisit $record): bool => $record->isCheckedOut() && ! $record->isConfirmed() && (auth()->user()?->can('update', $record) ?? false))
            ->action(function (FieldVisit $record): void {
                $to = app(FieldVisits::class)->requestConfirmation($record);

                $to
                    ? Notification::make()->title('Confirmation link sent to '.$to.'.')->success()->send()
                    : Notification::make()->title('Nowhere to send it: the contact has no email or WhatsApp number, or links are switched off.')->warning()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFieldVisits::route('/'),
            'create' => CreateFieldVisit::route('/create'),
            'edit' => EditFieldVisit::route('/{record}/edit'),
        ];
    }
}
