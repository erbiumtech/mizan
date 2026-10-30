<?php

namespace App\Modules\Crm\Filament\Resources\FieldVisits;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ScopesToAccessibleEmployees;
use App\Modules\Crm\Filament\Resources\FieldVisits\Pages\CreateFieldVisit;
use App\Modules\Crm\Filament\Resources\FieldVisits\Pages\EditFieldVisit;
use App\Modules\Crm\Filament\Resources\FieldVisits\Pages\ListFieldVisits;
use App\Modules\Crm\Models\FieldVisit;
use App\Modules\Crm\Models\FieldVisitPlace;
use App\Modules\Crm\Models\FieldVisitStaff;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Services\FieldVisits;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Support\EmployeeAccess;
use App\Support\ModuleMap;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                // Only the enrolled, where a company has switched enrolment on —
                // docs/saas-launch-plan.md §14.10. Off, this is every active employee,
                // exactly as before.
                ->options(fn (): array => Employee::query()->where('is_active', true)
                    ->whereIn('id', FieldVisitStaff::allowedEmployeeIds())->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])->all())
                ->searchable()
                ->required()
                ->default(fn (): ?int => app(EmployeeAccess::class)->ownEmployeeId(auth()->user())),

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

                /*
                 * Where the check-in stood against the client's pin — §14.10.
                 *
                 * Blank where nothing judged it, which is deliberately not the same as
                 * "away": a reader has to be able to tell "we looked and they were not
                 * there" from "we never looked".
                 */
                TextColumn::make('in_verdict')
                    ->label('Position')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => FieldVisit::VERDICTS[$state] ?? '')
                    ->color(fn (?string $state): string => match ($state) {
                        FieldVisit::VERDICT_HERE => 'success',
                        FieldVisit::VERDICT_AWAY => 'danger',
                        FieldVisit::VERDICT_UNVERIFIED => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (FieldVisit $record): ?string => $record->in_distance_m === null
                        ? null
                        : number_format($record->in_distance_m).'m from the pin'),
                TextColumn::make('outcome')->badge()->formatStateUsing(fn (?string $state): string => FieldVisit::OUTCOMES[$state] ?? '')->placeholder('—'),
                TextColumn::make('rating')
                    ->label('Rated')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '' : $state.'/5')
                    ->description(fn (FieldVisit $record): ?string => $record->rating_comment)
                    ->toggleable(),
                IconColumn::make('confirmed_at')->label('Confirmed')->boolean()->getStateUsing(fn (FieldVisit $record): bool => $record->isConfirmed())
                    ->tooltip(fn (FieldVisit $record): ?string => $record->confirmed_name ? 'by '.$record->confirmed_name : ($record->confirmation_sent_to ? 'sent to '.$record->confirmation_sent_to : null)),
            ])
            ->filters([
                // `employees.name` is null for anyone with a login — their name is on the user.
                SelectFilter::make('employee_id')->label('Visitor')
                    ->relationship('visitor', 'employee_id', fn (Builder $query) => $query->with('user'))
                    ->getOptionLabelFromRecordUsing(fn (Employee $record): string => $record->display_label),
                Filter::make('unconfirmed')->label('Unconfirmed')->query(fn (Builder $q) => $q->unconfirmed()),
                Filter::make('open')->label('Not yet done')->query(fn (Builder $q) => $q->whereNull('checked_out_at')),
            ])
            ->defaultSort('planned_at', 'desc')
            ->recordActions([
                static::checkInAction(),
                static::checkOutAction(),
                static::resendAction(),
                static::pinPlaceAction(),
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
                // §14.10. Optional: a visit without a photo is still a visit, and a rep
                // in a basement with no signal should not be stopped from checking out.
                FileUpload::make('photo_path')
                    ->label('Photo')
                    ->image()
                    ->disk('public')
                    ->directory('field-visits')
                    ->maxSize(4096)
                    ->imageResizeTargetWidth('1200')
                    ->helperText('Optional. The shop front, the meeting, the delivery — whatever makes the visit '
                        .'recognisable later.'),
            ])
            ->action(function (FieldVisit $record, array $data): void {
                try {
                    $visit = app(FieldVisits::class)->checkOut($record, static::position($data), $data['notes'] ?? null, $data['outcome'] ?? null);

                    if (filled($data['photo_path'] ?? null)) {
                        $visit->update(['photo_path' => $data['photo_path']]);
                    }

                    Notification::make()->title('Checked out.')
                        ->body($visit->confirmation_sent_to ? 'Confirmation link sent to '.$visit->confirmation_sent_to.'.' : 'No confirmation link was sent: no address, or links are switched off.')
                        ->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * Drop the pin on the client — docs/saas-launch-plan.md §14.10.
     *
     * A contact carries an address, not a point, and a distance to an address is a
     * geocoder and a dependency. This is the other answer: somebody standing at the
     * place saves where they are, once, and every visit to that client afterwards is
     * judged against it.
     *
     * Defaulted from this visit's own check-in, because the person pressing it is
     * usually the person who just arrived.
     */
    public static function pinPlaceAction(): Action
    {
        return Action::make('pinPlace')
            ->label(fn (FieldVisit $record): string => FieldVisitPlace::forSubject($record->subject_type, $record->subject_id) ? 'Move the pin' : 'Pin this place')
            ->icon('heroicon-o-map')
            ->color('gray')
            ->visible(fn (FieldVisit $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->modalHeading('Where this client is')
            ->modalDescription('Visits to this client are judged against this point. Nothing is judged until it is set.')
            ->fillForm(function (FieldVisit $record): array {
                $place = FieldVisitPlace::forSubject($record->subject_type, $record->subject_id);

                return [
                    'latitude' => $place?->latitude ?? $record->in_latitude,
                    'longitude' => $place?->longitude ?? $record->in_longitude,
                    'radius_m' => $place?->radius_m ?? FieldVisitPlace::DEFAULT_RADIUS_M,
                    'note' => $place?->note,
                ];
            })
            ->schema([
                TextInput::make('latitude')->numeric()->required(),
                TextInput::make('longitude')->numeric()->required(),
                TextInput::make('radius_m')
                    ->label('How close counts as here (metres)')
                    ->numeric()
                    ->minValue(20)
                    ->maxValue(5000)
                    ->required()
                    ->helperText('A shop front is twenty metres. A factory with its office at the back of the '
                        .'yard is two hundred.'),
                TextInput::make('note')->label('Note')->maxLength(190)->placeholder('Gate 2, behind the workshop'),
            ])
            ->action(function (FieldVisit $record, array $data): void {
                FieldVisitPlace::updateOrCreate(
                    ['subject_type' => $record->subject_type, 'subject_id' => $record->subject_id],
                    [
                        'latitude' => $data['latitude'],
                        'longitude' => $data['longitude'],
                        'radius_m' => (int) $data['radius_m'],
                        'note' => $data['note'] ?? null,
                        'pinned_by' => auth()->id(),
                    ],
                );

                Notification::make()->success()->title('Pinned. Visits here are judged against it from now on.')->send();
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
