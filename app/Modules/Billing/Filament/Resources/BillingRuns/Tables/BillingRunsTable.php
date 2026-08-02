<?php

namespace App\Modules\Billing\Filament\Resources\BillingRuns\Tables;

use App\Modules\Billing\Models\BillingRun;
use App\Modules\Billing\Services\MonthlyBillingService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BillingRunsTable
{
    /**
     * The statement lives outside the panel, so its URL names the company the
     * way the panel's own URLs do — see ResolveCompanyFromRoute for why it has
     * to be in there rather than inferred.
     *
     * @param  array<string, string>  $query
     */
    private static function statementUrl(BillingRun $run, array $query = []): string
    {
        return route('billing.statement', [
            'company' => Filament::getTenant()?->slug,
            'run' => $run->getKey(),
            ...$query,
        ]);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contact.name')->label('Client')->searchable()->sortable(),

                TextColumn::make('month')
                    ->label('Period')
                    ->state(fn (BillingRun $record): string => $record->periodLabel())
                    ->sortable(),

                TextColumn::make('invoice.invoice_number')
                    ->label('Invoice')
                    ->placeholder('Not built')
                    ->searchable(),

                TextColumn::make('invoice.total')
                    ->label('Total')
                    ->money('PKR')
                    ->placeholder('—')
                    ->alignEnd(),

                // What the client is actually asked for, at the rate agreed for
                // the month.
                TextColumn::make('client_total')
                    ->label('Quoted')
                    ->state(fn (BillingRun $record): string => $record->totalInClientCurrency() === null
                        ? '—'
                        : $record->currency.' '.number_format($record->totalInClientCurrency(), 2))
                    ->alignEnd(),

                TextColumn::make('invoice.status')
                    ->label('Status')
                    ->badge()
                    ->placeholder('draft')
                    ->color(fn (?string $state): string => match ($state) {
                        'issued', 'partially_paid' => 'warning',
                        'paid' => 'success',
                        'void' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('invoice_date')->date('d M Y')->sortable()->toggleable(),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->filters([
                SelectFilter::make('contact')->relationship('contact', 'name')->label('Client'),
            ])
            ->recordActions([
                // What the month contains, before committing to it. Reading the
                // figures back is how a wrong month or a missing expense gets
                // noticed — after issuing, correcting means voiding a posted
                // invoice.
                //
                // A page of its own rather than a modal: this is the sheet the
                // client is sent, it is read beside their own, and it wants the
                // width. New tab, so the month's list is still there behind it.
                Action::make('statement')
                    ->label('Statement')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (BillingRun $record): string => self::statementUrl($record), shouldOpenInNewTab: true),

                Action::make('statementPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->url(fn (BillingRun $record): string => self::statementUrl($record, ['format' => 'pdf']), shouldOpenInNewTab: true),

                Action::make('build')
                    ->label(fn (BillingRun $record): string => $record->invoice ? 'Rebuild invoice' : 'Build invoice')
                    ->icon('heroicon-o-document-plus')
                    ->requiresConfirmation()
                    ->modalDescription(fn (BillingRun $record): string => $record->invoice
                        ? 'The draft invoice will be rebuilt from the month as it now stands, replacing its lines.'
                        : 'A draft invoice will be raised from this month\'s payslips, expenses and advance repayments.')
                    ->visible(fn (BillingRun $record): bool => $record->isRebuildable()
                        && (auth()->user()?->can('BillingRunUpdate') ?? false))
                    ->action(function (BillingRun $record): void {
                        try {
                            $invoice = app(MonthlyBillingService::class)->build($record);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title("{$invoice->invoice_number} ready as a draft")
                            ->body(number_format((float) $invoice->total, 2).' across '.$invoice->lines()->count().' lines. Review it in Invoices before issuing.')
                            ->send();
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
