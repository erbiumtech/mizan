<?php

namespace App\Modules\Crm\Filament\Resources\Leads\Tables;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Services\LeadConversion;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company_name')
                    ->label('Company')
                    ->searchable()
                    ->sortable()
                    // Either half may be blank, so the person carries the row when the
                    // company does not.
                    ->placeholder('—')
                    ->description(fn (Lead $record): ?string => $record->person_name
                        ? trim($record->person_name.($record->title ? ', '.$record->title : ''))
                        : null),

                TextColumn::make('email')->searchable()->placeholder('—')->toggleable(),

                TextColumn::make('phone')->searchable()->placeholder('—')->toggleable(),

                TextColumn::make('source.name')->label('Source')->placeholder('—')->sortable()->toggleable(),

                TextColumn::make('owner.display_label')
                    ->label('Owner')
                    ->placeholder('—')
                    ->visible(fn (): bool => modules()->enabled('employees'))
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Lead::STATUS_NEW => 'gray',
                        Lead::STATUS_WORKING => 'info',
                        Lead::STATUS_QUALIFIED => 'warning',
                        Lead::STATUS_CONVERTED => 'success',
                        default => 'danger',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Lead::STATUS_NEW => 'New',
                        Lead::STATUS_WORKING => 'Working',
                        Lead::STATUS_QUALIFIED => 'Qualified',
                        Lead::STATUS_CONVERTED => 'Converted',
                        default => 'Lost',
                    })
                    // Whichever terminal state it reached, the reason travels with it:
                    // the customer it became, or why it was lost.
                    ->description(fn (Lead $record): ?string => match (true) {
                        $record->isConverted() => $record->convertedContact?->name
                            ? 'now '.$record->convertedContact->name
                            : null,
                        $record->status === Lead::STATUS_LOST => $record->lost_reason,
                        default => null,
                    })
                    ->sortable(),

                TextColumn::make('rating')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        Lead::RATING_HOT => 'danger',
                        Lead::RATING_WARM => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('estimated_value')
                    ->label('Est. value')
                    ->money('PKR')
                    ->placeholder('—')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')->label('Added')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    Lead::STATUS_NEW => 'New',
                    Lead::STATUS_WORKING => 'Working',
                    Lead::STATUS_QUALIFIED => 'Qualified',
                    Lead::STATUS_CONVERTED => 'Converted',
                    Lead::STATUS_LOST => 'Lost',
                ]),

                SelectFilter::make('lead_source_id')
                    ->label('Source')
                    ->options(fn (): array => LeadSource::orderBy('sort')->pluck('name', 'id')->all()),

                SelectFilter::make('rating')->options([
                    Lead::RATING_HOT => 'Hot',
                    Lead::RATING_WARM => 'Warm',
                    Lead::RATING_COLD => 'Cold',
                ]),
            ])
            ->recordActions([
                /**
                 * Converting. Absent — not disabled — at a company without Invoicing,
                 * because there is nothing for a lead to become there.
                 *
                 * It creates a Contact and nothing else. No invoice, no journal entry:
                 * a converted lead is a sales fact and an invoice is a legal document,
                 * which somebody raises deliberately.
                 */
                Action::make('convert')
                    ->label('Convert to customer')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('success')
                    ->visible(fn (Lead $record): bool => auth()->user()?->can('convert', $record) ?? false)
                    ->schema([
                        TextInput::make('name')
                            ->label('Customer name')
                            ->required()
                            ->maxLength(255)
                            ->default(fn (Lead $record): string => trim((string) $record->company_name) !== ''
                                ? $record->company_name
                                : (string) $record->person_name)
                            ->helperText('What the ledger will call them. Usually the company; for a sole trader, the person.'),

                        TextInput::make('ntn')
                            ->label('NTN')
                            ->maxLength(50)
                            ->helperText('Optional now, and needed before a sales-tax invoice can be reported to FBR.'),
                    ])
                    ->modalDescription('This creates a customer record. It does not raise an invoice — that stays a deliberate step.')
                    ->action(function (Lead $record, array $data): void {
                        try {
                            $contact = app(LeadConversion::class)->convert($record, array_filter([
                                'name' => $data['name'],
                                'ntn' => $data['ntn'] ?: null,
                            ], fn ($value) => $value !== null));
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title("Converted — {$contact->name} is now a customer.")
                            ->body('The lead stays on the record as where this customer came from.')
                            ->send();
                    }),

                Action::make('markLost')
                    ->label('Mark lost')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Lead $record): bool => $record->isOpen()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why')
                            ->required()
                            ->rows(2)
                            ->helperText('Required. A lost lead with no reason counts for nothing in win/loss, which is the report worth having.'),
                    ])
                    ->action(function (Lead $record, array $data): void {
                        try {
                            app(LeadConversion::class)->markLost($record, $data['reason']);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Marked lost, with the reason recorded.')->send();
                    }),

                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Deals do come back. This puts the lead back in play and clears the lost reason.')
                    ->visible(fn (Lead $record): bool => $record->status === Lead::STATUS_LOST
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Lead $record): void {
                        app(LeadConversion::class)->reopen($record);

                        Notification::make()->success()->title('Back in play.')->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
