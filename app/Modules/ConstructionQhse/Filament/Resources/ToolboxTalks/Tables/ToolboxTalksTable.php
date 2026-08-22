<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\ToolboxTalks\Tables;

use App\Modules\ConstructionQhse\Models\ToolboxTalk;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The talks register.
 *
 * **`Attended` is the column, not the row count.** §17.6 counts talks delivered *and attended*, because forty talks to
 * two people each is not a briefed site — and a register that only counted talks would report one.
 *
 * A talk with nobody recorded is **named** rather than shown as zero: a talk that was given and not written up is a
 * different fact from a talk nobody came to, and only the first is worth chasing.
 */
class ToolboxTalksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label('Ref')->sortable()->searchable(),

                TextColumn::make('topic')
                    ->wrap()
                    ->limit(60)
                    ->searchable()
                    ->description(fn (ToolboxTalk $record): ?string => $record->job?->code),

                TextColumn::make('delivered_at')
                    ->label('Given')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('presenter_label')->label('By')->placeholder('—')->toggleable(),

                /*
                 * The figure §17.6 counts.
                 */
                TextColumn::make('attendees')
                    ->label('Attended')
                    ->badge()
                    ->getStateUsing(fn (ToolboxTalk $record): string => $record->hasNoAttendees()
                        // Named: a talk given and not written up is not a talk nobody came to.
                        ? 'nobody recorded'
                        : $record->attendeeCount().' people')
                    ->color(fn (ToolboxTalk $record): string => $record->hasNoAttendees() ? 'warning' : 'success')
                    ->description(fn (ToolboxTalk $record): ?string => $record->hasNoAttendees()
                        ? null
                        : $record->signedCount().' signed'),

                TextColumn::make('prompted_by')
                    ->label('Prompted by')
                    ->placeholder('routine')
                    ->description(fn (ToolboxTalk $record): ?string => $record->incident?->incident_number)
                    ->color(fn (ToolboxTalk $record): string => $record->wasPrompted() ? 'info' : 'gray')
                    ->toggleable(),

                TextColumn::make('duration_minutes')->label('Mins')->alignEnd()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('job_id')->label('Job')->relationship('job', 'code')->searchable(),

                Filter::make('prompted')
                    ->label('Given in response to something')
                    ->query(fn (Builder $query): Builder => $query
                        ->where(fn ($q) => $q->whereNotNull('prompted_by')->orWhereNotNull('incident_id')))
                    ->toggle(),

                Filter::make('no_attendees')
                    ->label('Nobody recorded')
                    ->query(fn (Builder $query): Builder => $query->doesntHave('attendees'))
                    ->toggle(),
            ])
            ->defaultSort('delivered_at', 'desc')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ToolboxTalk $record): bool => auth()->user()?->can('update', $record) ?? false),
                DeleteAction::make()
                    // Only while nobody is recorded: an attendance sheet is somebody's evidence that they were told.
                    ->visible(fn (ToolboxTalk $record): bool => auth()->user()?->can('delete', $record) ?? false),
            ])
            ->emptyStateHeading('No toolbox talks')
            ->emptyStateDescription('Talks delivered and attended are both counted. Forty talks to two people each is not a briefed site.');
    }
}
