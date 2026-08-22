<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Tables;

use App\Modules\ConstructionQhse\Models\SitePersonnel as Person;
use App\Modules\ConstructionQhse\Services\SitePersonnelService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The gate register.
 *
 * **`Cleared` is the column this screen exists for**: inducted, and no mandatory ticket lapsed. Everything else is
 * supporting detail.
 *
 * **Never inducted and lapsed are shown as different things**, because they are different conversations: one person has
 * to be put through an induction and the other has to be put through it again, and a single "not inducted" state would
 * hide which a site has.
 */
class SitePersonnelTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Person $record): ?string => $record->employer),

                TextColumn::make('trade')->placeholder('—')->toggleable(),

                TextColumn::make('job.code')->label('Job')->sortable()->toggleable(),

                /*
                 * **The column the register is for.**
                 */
                TextColumn::make('cleared')
                    ->label('Cleared')
                    ->badge()
                    ->getStateUsing(function (Person $record): string {
                        if ($record->inducted_on === null) {
                            return 'never inducted';
                        }

                        if ($record->inductionLapsed()) {
                            return 'induction lapsed';
                        }

                        $lapsed = $record->expiredMandatoryCompetencies();

                        return $lapsed->isEmpty()
                            ? 'yes'
                            : $lapsed->count().' ticket(s) lapsed';
                    })
                    ->color(fn (Person $record): string => $record->isClearedToWork() ? 'success' : 'danger')
                    ->tooltip('Inducted, and no mandatory ticket lapsed. A gateman\'s question.'),

                TextColumn::make('induction_valid_to')
                    ->label('Induction to')
                    ->date('d M Y')
                    // Named rather than blank: an induction with no end is a claim about this site's policy.
                    ->placeholder(fn (Person $record): string => $record->inducted_on === null ? '—' : 'no expiry set')
                    ->color(fn (Person $record): string => $record->inductionLapsed() ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('competencies_count')
                    ->label('Tickets')
                    ->getStateUsing(fn (Person $record): int => $record->competencies->count())
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('is_active')
                    ->label('On site')
                    ->badge()
                    ->getStateUsing(fn (Person $record): string => $record->is_active ? 'yes' : 'left')
                    ->color(fn (Person $record): string => $record->is_active ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),

                Filter::make('active')
                    ->label('Currently on site')
                    ->query(fn (Builder $query): Builder => $query->active())
                    ->default()
                    ->toggle(),

                Filter::make('never_inducted')
                    ->label('Never inducted')
                    ->query(fn (Builder $query): Builder => $query->neverInducted())
                    ->toggle(),

                Filter::make('induction_lapsed')
                    ->label('Induction lapsed')
                    ->query(fn (Builder $query): Builder => $query->inductionLapsed())
                    ->toggle(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Person $record): bool => auth()->user()?->can('update', $record) ?? false),

                Action::make('induct')
                    ->label('Induct')
                    ->icon('heroicon-o-academic-cap')
                    ->color('success')
                    ->modalHeading('Record an induction')
                    ->modalDescription('The end date matters as much as the start: an induction is not a permanent state, and a register that treated it as one would report full coverage on a site with none.')
                    ->schema([
                        DatePicker::make('on')->label('Inducted on')->native(false)->default(now())->required(),
                        DatePicker::make('valid_to')
                            ->label('Valid to')
                            ->native(false)
                            ->helperText('This site\'s induction policy — twelve months on one job, the contract duration on another.'),
                    ])
                    ->visible(fn (Person $record): bool => auth()->user()?->can('induct', $record) ?? false)
                    ->action(fn (Person $record, array $data) => static::run(
                        fn () => app(SitePersonnelService::class)->induct(
                            $record,
                            $data['on'] ?? null,
                            $data['valid_to'] ?? null,
                        ),
                        'Inducted.',
                        'They show as cleared once no mandatory ticket has lapsed either.',
                    )),

                Action::make('deactivate')
                    ->label('Left site')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('gray')
                    ->modalDescription('Taking somebody off the register is what makes a lapsed ticket historical rather than urgent. The row stays.')
                    ->schema([
                        DatePicker::make('last_on_site')->native(false)->default(now()),
                    ])
                    ->visible(fn (Person $record): bool => (auth()->user()?->can('update', $record) ?? false)
                        && $record->is_active)
                    ->action(fn (Person $record, array $data) => static::run(
                        fn () => app(SitePersonnelService::class)->deactivate($record, $data['last_on_site'] ?? null),
                        'Marked as off site.',
                        'Their tickets stop appearing in the daily expiry warnings.',
                    )),

                DeleteAction::make()
                    // Only while nothing has been recorded: somebody who was inducted or attended a talk is part of
                    // those records, and a register that could delete them would let a site's history be tidied.
                    ->visible(fn (Person $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('Nobody on the register')
            ->emptyStateDescription('A name is all that is required. Most people on most sites are somebody else\'s employees.');
    }

    /** Service refusals are sentences somebody needs to read, so they are surfaced rather than thrown. */
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
