<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Itps\RelationManagers;

use App\Modules\ConstructionQhse\Models\Itp;
use App\Modules\ConstructionQhse\Models\ItpActivity;
use App\Modules\ConstructionQhse\Models\ItpActivityParty;
use App\Modules\ConstructionQhse\Services\InspectionService;
use App\Modules\ConstructionQhse\Services\ItpService;
use App\Support\TenantDb;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The plan's points — §17.1.
 *
 * **The point type is the field this whole screen is for.** §17.1: "a **hold** point means work may not proceed past it;
 * a **witness** point means a party is invited and work may proceed if they do not attend; a **review** point is
 * documentation only."
 *
 * So the type is a labelled radio-style select whose options say what each one *does* rather than naming it, and
 * **Who attends** is its own action rather than a field — because a hold point can require the Engineer to approve *and*
 * a laboratory to verify, which is one point and two parties.
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Points';

    private function itp(): Itp
    {
        /** @var Itp $itp */
        $itp = $this->getOwnerRecord();

        return $itp;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('sequence')->numeric()->helperText('Left blank, it goes on the end.'),

                Select::make('point_type')
                    ->label('Point type')
                    ->options(ItpActivity::POINT_TYPES)
                    ->default(ItpActivity::POINT_REVIEW)
                    ->required()
                    ->live()
                    ->helperText('The entire reason the document exists. A hold point stops work; a witness point does not.'),

                Textarea::make('activity_description')
                    ->label('Activity')
                    ->required()
                    ->rows(2)
                    ->columnSpanFull(),

                TextInput::make('reference_standard')
                    ->maxLength(255)
                    ->helperText('BS EN 206, ACI 318, the specification clause.'),

                TextInput::make('inspection_method')->maxLength(255),

                Textarea::make('acceptance_criteria')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('What the inspection is judged against — a value, not a sentiment.'),

                TextInput::make('frequency')
                    ->maxLength(255)
                    ->helperText('Every pour, one in twenty, 100%.'),

                TextInput::make('record_form')->maxLength(255),

                TextInput::make('notice_hours')
                    ->label('Notice required (hours)')
                    ->numeric()
                    ->helperText('A term of this plan for this activity: 24 hours for a rebar inspection, a week for a third-party load test. Snapshotted onto every inspection raised against this point.'),

                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('activity_description')
            ->defaultSort('sequence')
            ->columns([
                TextColumn::make('sequence')->label('#')->sortable(),

                TextColumn::make('activity_description')
                    ->label('Activity')
                    ->wrap()
                    ->limit(60)
                    ->description(fn (ItpActivity $record): ?string => $record->reference_standard),

                /*
                 * Coloured by what it *does*: red stops work, amber invites somebody, grey is paperwork.
                 */
                TextColumn::make('point_type')
                    ->label('Point')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        ItpActivity::POINT_HOLD => 'Hold',
                        ItpActivity::POINT_WITNESS => 'Witness',
                        ItpActivity::POINT_REVIEW => 'Review',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        ItpActivity::POINT_HOLD => 'danger',
                        ItpActivity::POINT_WITNESS => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(fn (ItpActivity $record): string => $record->pointLabel()),

                TextColumn::make('notice_hours')
                    ->label('Notice')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : "{$state} h")
                    // Named rather than blank: a hold point with no notice period stops work the day somebody
                    // remembers it.
                    ->placeholder(fn (ItpActivity $record): string => $record->isHoldPoint() ? 'none set' : '—')
                    ->color(fn (ItpActivity $record): string => $record->isHoldPoint() && $record->notice_hours === null
                        ? 'warning'
                        : 'gray'),

                TextColumn::make('parties')
                    ->label('Who attends')
                    ->getStateUsing(fn (ItpActivity $record): ?string => $record->parties->isEmpty()
                        ? null
                        : $record->parties->map(fn (ItpActivityParty $p): string => $p->describe())->implode('; '))
                    // A hold point naming nobody is what issuing the plan refuses, so it is named here first.
                    ->placeholder(fn (ItpActivity $record): string => $record->isHoldPoint() ? 'nobody named' : '—')
                    ->color(fn (ItpActivity $record): string => $record->isHoldPoint() && $record->parties->isEmpty()
                        ? 'danger'
                        : 'gray')
                    ->wrap(),

                TextColumn::make('acceptance_criteria')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Filter::make('hold')
                    ->label('Hold points only')
                    ->query(fn (Builder $query): Builder => $query->holdPoints())
                    ->toggle(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->itp()) ?? false)
                    ->using(fn (array $data): Model => app(ItpService::class)->addActivity($this->itp(), $data)),
            ])
            ->recordActions([
                /*
                 * **Who attends** — its own action because it is a list, not a field.
                 *
                 * §17.1's pivot exists because one column cannot say *the Engineer witnesses, a third-party laboratory
                 * verifies, the Employer approves*, and that sentence is what a hold point on a structural pour means.
                 */
                Action::make('addParty')
                    ->label('Add party')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->itp()) ?? false)
                    ->schema([
                        Select::make('party')->options(ItpActivityParty::PARTIES)->required(),
                        Select::make('role')
                            ->options(ItpActivityParty::ROLES)
                            ->default('witnesses')
                            ->required()
                            ->helperText('Separate from the party: one point can need the Engineer to approve and a laboratory to verify.'),
                        Toggle::make('attendance_mandatory')
                            ->label('Attendance mandatory')
                            ->helperText('At a witness point, leaving this off is what lets work proceed when they do not come.'),
                        Select::make('contact_id')
                            ->label('Named individual')
                            ->options(fn (): array => static::contacts())
                            ->searchable()
                            ->visible(fn (): bool => modules()->enabled('invoicing')),
                        TextInput::make('party_label')->label('Party, in words')->maxLength(255),
                    ])
                    ->action(fn (ItpActivity $record, array $data) => static::run(
                        fn () => app(ItpService::class)->addParty($record, $data),
                        'Party added.',
                        'A hold point that names nobody cannot be issued.',
                    )),

                /*
                 * Requesting an inspection from the plan row, which is the path that snapshots the point type and the
                 * notice period onto it.
                 */
                Action::make('requestInspection')
                    ->label('Request inspection')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('info')
                    ->visible(fn (ItpActivity $record): bool => $this->itp()->isInForce()
                        && $record->is_active
                        && (auth()->user()?->can('ConstructionInspectionUpdate') ?? false))
                    ->schema([
                        Textarea::make('activity_description')->label('What is being inspected')->rows(2),
                        TextInput::make('reference')->maxLength(255)->helperText('Left blank, it is numbered for you.'),
                    ])
                    ->action(fn (ItpActivity $record, array $data) => static::run(
                        fn () => app(InspectionService::class)->requestAgainst($record, array_filter(
                            $data,
                            fn ($value): bool => $value !== null && $value !== '',
                        )),
                        'Inspection requested.',
                        'The point type and the notice period are snapshotted onto it, so a later revision of this plan cannot change what the inspection meant.',
                    )),

                EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->itp()) ?? false),
                DeleteAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->itp()) ?? false),
            ])
            ->emptyStateHeading('No points yet')
            ->emptyStateDescription('An ITP with no points is a cover sheet — issuing refuses one.');
    }

    /**
     * Contacts, read through the query builder.
     *
     * Invoicing owns them and this module does not require it, so the picker is hidden without the module and this never
     * names `Contact` from a table action.
     *
     * @return array<int, string>
     */
    private static function contacts(): array
    {
        if (! modules()->enabled('invoicing')) {
            return [];
        }

        return TenantDb::table('contacts')->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }

    /** Service refusals are sentences somebody needs to read. */
    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
