<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\RetentionMovements\RetentionMovementResource;
use App\Modules\ConstructionContracts\Models\Contract;
use App\Modules\ConstructionContracts\Models\RetentionMovement;
use App\Modules\ConstructionContracts\Services\RetentionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

/**
 * The ledger, with the two acts that write to it as header actions.
 *
 * **Release** and **Forfeit or adjust** are header actions rather than row actions because they are not about a
 * movement — they are about a contract, and they create a movement. A row action would suggest that releasing is
 * something you do *to* the row where the money was held, which is exactly the mental model this ledger exists to
 * replace.
 */
class ListRetentionMovements extends ListRecords
{
    protected static string $resource = RetentionMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-retention', 'Retention: Help'),

            Action::make('release')
                ->label('Release')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->modalHeading('Release retention')
                ->modalDescription('The amount offered is what the contract\'s release rule says is due at that stage. Releasing before the trigger date is allowed — sectional taking-over is ordinary — but it needs a reason.')
                ->schema([
                    Select::make('contract_id')
                        ->label('Contract')
                        ->options(fn (): array => static::contractOptions())
                        ->searchable()
                        ->required()
                        ->live(),

                    Select::make('stage')
                        ->label('Stage')
                        ->options([
                            RetentionMovement::STAGE_FIRST_RELEASE => 'First release — at completion',
                            RetentionMovement::STAGE_FINAL_RELEASE => 'Final release — at the end of the defects period',
                        ])
                        ->default(RetentionMovement::STAGE_FIRST_RELEASE)
                        ->selectablePlaceholder(false)
                        ->required(),

                    TextInput::make('amount')
                        ->numeric()
                        ->helperText('Left blank, whatever the release rule says is due.'),

                    Textarea::make('reason')
                        ->rows(2)
                        ->helperText('Required when the release is not yet due.'),
                ])
                ->visible(fn (): bool => auth()->user()?->can('create', RetentionMovement::class) ?? false)
                ->action(function (array $data): void {
                    static::run(fn () => app(RetentionService::class)->release(
                        Contract::query()->findOrFail($data['contract_id']),
                        $data['stage'],
                        ($data['amount'] ?? null) === null || $data['amount'] === '' ? null : (float) $data['amount'],
                        $data['reason'] ?? null,
                    ), 'Released.', 'The ledger carries the movement and the balance follows it.');
                }),

            Action::make('adjust')
                ->label('Forfeit or adjust')
                ->icon('heroicon-o-scale')
                ->color('warning')
                ->modalHeading('Record a movement')
                ->modalDescription('A forfeit against uncorrected defects, a bond substituting for cash, or an agreed adjustment. Each of these is a decision rather than arithmetic, which is why this ledger exists at all.')
                ->schema([
                    Select::make('contract_id')
                        ->label('Contract')
                        ->options(fn (): array => static::contractOptions())
                        ->searchable()
                        ->required(),

                    Select::make('kind')
                        ->label('Kind')
                        ->options([
                            RetentionMovement::KIND_FORFEITED => 'Forfeited — against uncorrected defects',
                            RetentionMovement::KIND_SUBSTITUTED_BY_BOND => 'Substituted by a bond',
                            RetentionMovement::KIND_ADJUSTED => 'Adjusted — an agreed one-off',
                            RetentionMovement::KIND_REINSTATED => 'Reinstated',
                        ])
                        ->required(),

                    TextInput::make('amount')
                        ->numeric()
                        ->required()
                        ->helperText('A positive figure. A forfeit or a substitution reduces what is held; the sign is applied for you.'),

                    Textarea::make('reason')
                        ->rows(3)
                        ->required()
                        ->helperText('This is the row a final account argues about. Write the sentence that settles it.'),
                ])
                ->visible(fn (): bool => auth()->user()?->can('create', RetentionMovement::class) ?? false)
                ->action(function (array $data): void {
                    static::run(fn () => app(RetentionService::class)->record(
                        Contract::query()->findOrFail($data['contract_id']),
                        $data['kind'],
                        (float) $data['amount'],
                        $data['reason'],
                    ), 'Recorded.', 'The reason is on the row.');
                }),
        ];
    }

    /** @return array<int, string> */
    private static function contractOptions(): array
    {
        return Contract::query()
            ->whereNot('status', Contract::STATUS_DRAFT)
            ->with('job')
            ->get()
            ->mapWithKeys(fn (Contract $c): array => [
                $c->getKey() => "{$c->job?->code} · {$c->contract_number} — {$c->title}",
            ])
            ->all();
    }

    private static function run(callable $call, string $title, string $body): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($title)->body($body)->send();
    }
}
