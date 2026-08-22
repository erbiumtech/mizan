<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\RelationManagers;

use App\Modules\ConstructionQhse\Models\Competency;
use App\Modules\ConstructionQhse\Models\SitePersonnel as Person;
use App\Modules\ConstructionQhse\Services\SitePersonnelService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Somebody's tickets — §17.5.
 *
 * **`Mandatory` is the field that turns an expiry into a stoppage.** A first-aid certificate lapsing is a gap; a
 * confined-space ticket lapsing on somebody who is in a chamber this morning is an emergency, and only the flag can tell
 * them apart.
 *
 * **Renewing clears the warning ladder**, which is the point of doing it through an action rather than by editing the
 * date: a renewed ticket has to be able to warn again next year, and a stale `expiry_notified_at_days` would silence it
 * for good.
 */
class CompetenciesRelationManager extends RelationManager
{
    protected static string $relationship = 'competencies';

    protected static ?string $title = 'Tickets';

    private function person(): Person
    {
        /** @var Person $person */
        $person = $this->getOwnerRecord();

        return $person;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('kind')->options(Competency::KINDS)->default('training')->required(),
                TextInput::make('title')->required()->maxLength(255),
                TextInput::make('reference')->label('Certificate or licence number')->maxLength(255),
                TextInput::make('issuing_body')->maxLength(255),
                DatePicker::make('issued_on')->native(false),
                DatePicker::make('expires_on')
                    ->native(false)
                    ->helperText('Leave blank only where it genuinely does not expire — a date nobody has is worse than an honest blank.'),
                Toggle::make('is_mandatory')
                    ->label('Mandatory for the work they do')
                    ->columnSpanFull()
                    ->helperText('This is what turns an expiry into a stoppage. A first-aid certificate lapsing is a gap; a confined-space ticket lapsing on somebody in a chamber is an emergency.'),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('expires_on')
            ->columns([
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Competency::KINDS[$state] ?? $state)
                    ->color('gray'),

                TextColumn::make('title')
                    ->wrap()
                    ->description(fn (Competency $record): ?string => $record->reference),

                TextColumn::make('issuing_body')->label('Issued by')->placeholder('—')->toggleable(),

                TextColumn::make('expires_on')
                    ->label('Expires')
                    ->date('d M Y')
                    // Named: "never expires" is a claim, and a blank cell is not.
                    ->placeholder('never expires')
                    ->description(fn (Competency $record): ?string => match (true) {
                        $record->neverExpires() => null,
                        $record->hasExpired() => abs((int) $record->daysUntilExpiry()).' days ago',
                        default => (int) $record->daysUntilExpiry().' days left',
                    })
                    ->color(fn (Competency $record): string => match (true) {
                        $record->hasExpired() && $record->is_mandatory => 'danger',
                        $record->hasExpired() => 'warning',
                        (int) ($record->daysUntilExpiry() ?? 999) <= 30 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('is_mandatory')
                    ->label('Mandatory')
                    ->badge()
                    ->getStateUsing(fn (Competency $record): ?string => $record->is_mandatory ? 'yes' : null)
                    ->placeholder('—')
                    ->color(fn (Competency $record): string => $record->hasExpired() ? 'danger' : 'gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->person()) ?? false)
                    ->using(fn (array $data): Model => app(SitePersonnelService::class)
                        ->addCompetency($this->person(), $data)),
            ])
            ->recordActions([
                /*
                 * **Renewing through an action rather than by editing the date**, because it clears the warning ladder.
                 */
                Action::make('renew')
                    ->label('Renew')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->modalDescription('This clears the warning history as well as moving the date, so the ticket can warn again next year. Editing the date alone would silence it for good.')
                    ->schema([
                        DatePicker::make('issued_on')->native(false),
                        DatePicker::make('expires_on')->native(false)->required(),
                    ])
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->person()) ?? false)
                    ->action(fn (Competency $record, array $data) => static::run(
                        fn () => app(SitePersonnelService::class)->renewCompetency(
                            $record,
                            $data['expires_on'],
                            $data['issued_on'] ?? null,
                        ),
                        'Renewed.',
                        'The expiry warnings will fire again as the new date approaches.',
                    )),

                EditAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->person()) ?? false),
                DeleteAction::make()->visible(fn (): bool => auth()->user()?->can('update', $this->person()) ?? false),
            ])
            ->emptyStateHeading('No tickets recorded')
            ->emptyStateDescription('Training, licences, certifications, medicals, authorisations. Mark the ones that are mandatory for the work — those are the ones whose expiry stops somebody working.');
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
