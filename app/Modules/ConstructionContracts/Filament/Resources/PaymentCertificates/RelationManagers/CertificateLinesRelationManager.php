<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\PaymentCertificates\RelationManagers;

use App\Modules\ConstructionContracts\Models\CertificateLine;
use App\Modules\ConstructionContracts\Models\PaymentCertificate;
use App\Modules\ConstructionContracts\Services\CertificationService;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The continuation sheet on screen — `docs/construction-management-plan.md` §8.4's column mapping.
 *
 * Columns D and E are the pair worth understanding: **D is a frozen snapshot** of the previous certificate and E
 * is derived from it. That is why voiding certificate 6 does not change what certificate 7 says, and why a
 * corrected earlier certificate is absorbed rather than compounded.
 *
 * A certifier edits the cumulative figures here — certifying less than was claimed is the ordinary case, and it is
 * the reason the claim and the certificate are two documents. Editing recomputes the header, so the bottom line
 * always agrees with the lines above it.
 */
class CertificateLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Certified lines';

    private function isDraft(): bool
    {
        /** @var PaymentCertificate $certificate */
        $certificate = $this->getOwnerRecord();

        return $certificate->isDraft();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('cumulative_work_value')
                    ->label('Work value to date')
                    ->numeric()
                    ->required()
                    ->helperText('Cumulative. Certifying less than was claimed is ordinary; this is where it happens.'),

                TextInput::make('cumulative_materials_value')
                    ->label('Materials on site to date')
                    ->numeric()
                    ->helperText('Delivered and not built in.'),

                TextInput::make('line_retention')
                    ->label('Retention on this line')
                    ->numeric()
                    ->helperText('Recomputed from the contract rate unless you override it here.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('item_no')
            ->columns([
                TextColumn::make('item_no')
                    ->label('A')
                    ->tooltip('Item')
                    ->sortable(),

                TextColumn::make('description')
                    ->label('B')
                    ->tooltip('Description')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('scheduled_value')
                    ->label('C')
                    ->tooltip('Scheduled value')
                    ->money('PKR')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('PKR')),

                TextColumn::make('previous_work_value')
                    ->label('D')
                    ->tooltip('Work completed from previous applications — a frozen snapshot')
                    ->money('PKR')
                    ->alignEnd(),

                TextColumn::make('this_period')
                    ->label('E')
                    ->tooltip('Work completed this period — derived from D and the cumulative figure')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (CertificateLine $record): float => $record->workThisPeriod()),

                TextColumn::make('cumulative_materials_value')
                    ->label('F')
                    ->tooltip('Materials presently stored')
                    ->money('PKR')
                    ->alignEnd(),

                TextColumn::make('total_to_date')
                    ->label('G')
                    ->tooltip('Total completed and stored to date')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (CertificateLine $record): float => $record->totalToDate())
                    ->weight('bold'),

                TextColumn::make('balance')
                    ->label('H')
                    ->tooltip('Balance to finish')
                    ->money('PKR')
                    ->alignEnd()
                    ->state(fn (CertificateLine $record): float => $record->balanceToFinish())
                    // Negative means certified beyond the scheduled value, which is worth seeing in red rather
                    // than reading as an ordinary balance.
                    ->color(fn (CertificateLine $record): string => $record->balanceToFinish() < 0 ? 'danger' : 'gray'),

                TextColumn::make('line_retention')
                    ->label('I')
                    ->tooltip('Retainage')
                    ->money('PKR')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('PKR')),

                TextColumn::make('percent')
                    ->label('%')
                    ->alignEnd()
                    // Null on a line with no scheduled value: 0% against an omission reads as work not started.
                    ->placeholder('—')
                    ->state(fn (CertificateLine $record): ?string => $record->percentComplete() === null
                        ? null
                        : number_format($record->percentComplete(), 1).'%')
                    ->toggleable(),
            ])
            ->defaultSort('item_no')
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->isDraft())
                    ->after(fn () => app(CertificationService::class)->recompute($this->getOwnerRecord()->refresh())),
            ])
            ->emptyStateHeading('No lines')
            ->emptyStateDescription('Lines are written when the certificate is prepared, one per claimable schedule line.');
    }
}
