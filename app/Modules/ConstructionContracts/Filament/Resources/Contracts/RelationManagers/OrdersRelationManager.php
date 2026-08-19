<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Contracts\RelationManagers;

use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Services\CertificateCommitmentService;
use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\ConstructionCosting\Services\CommitmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The order behind a subcontract, and what certification has left open — §5, §12, Phase 6c.
 *
 * A subcontract is two rows and one agreement (§5): this contract carries the schedule, the variations, the
 * certificates and the retention, and a commitment carries the money promised. The link is what makes the
 * four-column report's *committed* column fall as work is certified, instead of showing the whole subcontract as
 * promised for the life of the job.
 *
 * **The link is made from here rather than by a picker on the order form, and that is a structural constraint
 * rather than a preference.** Neither module requires the other, so whichever names the other is a guarded
 * coupling — and if both named each other the pair would be a cycle, which composer cannot express, so neither
 * could ever be packaged. `construction_contracts` is the side that has to reach, because the certificate is what
 * triggers the relief. See `CertificateCommitmentService`.
 *
 * Linking and unlinking both run the sync, so an order linked to a contract that has already been certified is
 * relieved at once, and one taken off gives its relief back. An order left half-relieved after being unlinked
 * would read as part-delivered with no certificate anywhere saying so.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'commitments';

    protected static ?string $title = 'Orders and commitment';

    /** Payable only: money coming in from an employer was never committed to anybody, so there is nothing to show. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Contract $ownerRecord */
        return $ownerRecord->side === Contract::SIDE_PAYABLE
            && modules()->enabled('construction_costing');
    }

    private function contract(): Contract
    {
        /** @var Contract $contract */
        $contract = $this->getOwnerRecord();

        return $contract;
    }

    private function mayLink(): bool
    {
        return auth()->user()?->can('update', $this->contract()) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            // The totals below are computed per row, which §18.3 names as the report trap. It is bounded here
            // because a contract has an order or two rather than five hundred — and the eager load is what keeps
            // even that from being a query per line.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('lines.reliefs'))
            ->columns([
                TextColumn::make('number')
                    ->label('Order')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        // Only an issued order commits money, so anything before that is grey however far along it
                        // looks — an approved order in a drawer can still be withdrawn with a phone call.
                        Commitment::STATUS_ISSUED, Commitment::STATUS_PARTIALLY_RELIEVED => 'success',
                        Commitment::STATUS_CANCELLED => 'danger',
                        Commitment::STATUS_CLOSED => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', $state))
                    ->sortable(),

                // No summariser on these three, and the reason is worth a line: they are computed from the lines and
                // their reliefs, so `Sum` would go looking for an `ordered` column on `construction_commitments`
                // and the query fails outright. Which is the good outcome — a summariser that silently summed the
                // wrong thing is how a total comes to disagree with the rows above it.
                TextColumn::make('ordered')
                    ->label('Ordered')
                    ->money('PKR')
                    ->alignEnd()
                    ->getStateUsing(fn (Commitment $record): float => $record->orderedTotal()),

                TextColumn::make('relieved')
                    ->label('Relieved')
                    ->money('PKR')
                    ->alignEnd()
                    ->getStateUsing(fn (Commitment $record): float => $record->relievedTotal())
                    ->description(fn (Commitment $record): ?string => $record->overRelieved()
                        // Not hidden and not capped: an order relieved past its value was priced below what has been
                        // certified against it, and that is a finding rather than a display problem.
                        ? 'More relieved than ordered'
                        : null),

                TextColumn::make('open')
                    ->label('Still committed')
                    ->money('PKR')
                    ->alignEnd()
                    ->getStateUsing(fn (Commitment $record): float => $record->openTotal()),
            ])
            ->defaultSort('number')
            ->headerActions([
                Action::make('link')
                    ->label('Link an order')
                    ->icon('heroicon-o-link')
                    ->modalHeading('Link the order behind this subcontract')
                    ->modalDescription('The contract holds the schedule and the certificates; the order holds the money promised. Linked, every certificate issued here relieves the commitment — so the cost report stops showing money as promised once it has been certified.')
                    ->schema([
                        Select::make('commitment_id')
                            ->label('Order')
                            ->options(fn (): array => Commitment::query()
                                ->whereNull('contract_id')
                                ->open()
                                ->orderBy('number')
                                ->get()
                                ->mapWithKeys(fn (Commitment $order): array => [
                                    $order->getKey() => $order->displayName(),
                                ])
                                ->all())
                            ->searchable()
                            ->required()
                            ->helperText('Orders not already linked to a contract, and not closed or cancelled. Raise the order under Construction if it is not here yet.'),
                    ])
                    ->visible(fn (): bool => $this->mayLink())
                    ->action(function (array $data): void {
                        $order = Commitment::findOrFail($data['commitment_id']);
                        $order->update(['contract_id' => $this->contract()->getKey()]);

                        // At once, not at the next certificate: an order linked to a subcontract that is already
                        // half certified would otherwise read as wholly open against work that is finished.
                        $reliefs = app(CertificateCommitmentService::class)->syncFor(
                            $this->contract(),
                            now()->toDateString(),
                        );

                        Notification::make()
                            ->success()
                            ->title("{$order->number} linked.")
                            ->body($reliefs === []
                                ? 'Nothing has been certified yet, so the whole order is still committed.'
                                : 'Relieved to the value certified so far on this subcontract.')
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('unlink')
                    ->label('Unlink')
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Take this order off the subcontract')
                    ->modalDescription('The relief the certificates wrote is given back as a negative movement rather than deleted, so what the commitment looked like last month stays answerable. The order itself is untouched.')
                    ->visible(fn (): bool => $this->mayLink())
                    ->action(function (Commitment $record): void {
                        $given = app(CommitmentService::class)->reverseCertificationRelief(
                            $record,
                            now()->toDateString(),
                        );

                        $record->update(['contract_id' => null]);

                        Notification::make()
                            ->success()
                            ->title("{$record->number} unlinked.")
                            ->body($given === []
                                ? 'Nothing had been relieved against it.'
                                : 'The certified relief has been given back, and the order is committed again.')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No order linked')
            ->emptyStateDescription('Without one, this subcontract certifies and pays but puts nothing on the cost report\'s committed column — so the job reads as though nothing had been promised downward.');
    }
}
