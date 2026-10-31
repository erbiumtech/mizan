<?php

namespace App\Modules\Lifecycle\Filament\Resources\FinalSettlements;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\RecordSheet;
use App\Modules\Employees\Models\Employee;
use App\Modules\Lifecycle\Filament\Resources\FinalSettlements\Pages\ListFinalSettlements;
use App\Modules\Lifecycle\Models\FinalSettlement;
use App\Modules\Lifecycle\Services\FinalSettlementBuilder;
use App\Support\CompanyCurrency;
use App\Support\LandlordUserColumn;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;
use UnitEnum;

/**
 * What somebody is owed, or owes, on leaving.
 *
 * **A proposal, not a posting.** Nothing on this screen writes to the ledger. Approving
 * records that a figure was agreed; paying it goes through the existing payslip or
 * payment path, which posts correctly. A second money path with its own journal entries
 * is how a ledger stops reconciling — docs/hrms-plan.md §4.6.
 */
class FinalSettlementResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = FinalSettlement::class;

    /** Where its records open from the list — docs/side-sheets.md. */
    protected static RecordSheet $recordSheet = RecordSheet::Page;

    /**
     * A mere viewer (SettlementView only, a line manager) sees their own reporting downline; a settlement
     * manager (Approve/Update) or a privileged role sees them all — security audit finding 9. Without this
     * anyone with SettlementView read every leaver's figures regardless of hierarchy.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! static::seesEverySettlement($user)) {
            $query->whereIn('employee_id', app(\App\Support\EmployeeAccess::class)->accessibleEmployeeIds($user)->all());
        }

        return $query;
    }

    /** Whoever manages settlements, rather than merely reading their own line, sees all of them. */
    public static function seesEverySettlement(\App\Modules\Core\Models\User $user): bool
    {
        return app(\App\Support\EmployeeAccess::class)->isPrivileged($user)
            || $user->canAny(['SettlementCreate', 'SettlementUpdate', 'SettlementApprove', 'SettlementDelete']);
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $modelLabel = 'Final settlement';

    protected static ?int $navigationSort = 63;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Who, and when')
                ->schema([
                    Select::make('employee_id')
                        ->label(__('Employee'))
                        ->options(fn (): array => Employee::query()
                            ->orderBy('employee_id')
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                            ->all())
                        ->searchable()
                        ->required()
                        ->disabledOn('edit'),

                    DatePicker::make('left_on')->native(false)->required(),
                ])
                ->columns(2),

            // The clearance list, read here — docs/saas-launch-plan.md §14.5.
            Section::make('Clearance')
                ->schema([
                    \Filament\Forms\Components\Placeholder::make('clearance')
                        ->label(__('Items'))
                        ->content(function ($get): string {
                            $separation = $get('employee_id') ? \App\Modules\Lifecycle\Models\Separation::query()->where('employee_id', $get('employee_id'))->orderByDesc('id')->first() : null;

                            if ($separation === null) {
                                return 'No separation recorded; open one under Lifecycle → Separations to build the clearance list.';
                            }

                            $c = $separation->clearance();

                            return "{$c['clear']} of {$c['total']} cleared or waived".($c['clear'] < $c['total'] ? ' — not yet clear' : ' — all clear');
                        }),
                ])
                ->visible(fn ($get): bool => filled($get('employee_id'))),

            Section::make('Owed to them')
                ->schema([
                    TextInput::make('leave_encashment_days')->label(__('Encashable leave (days)'))->numeric()->step(0.5)->default(0),
                    TextInput::make('leave_encashment_amount')->label(__('Leave encashment'))->numeric()->default(0),
                    TextInput::make('gratuity_amount')
                        ->label(__('Gratuity'))
                        ->numeric()
                        ->default(0)
                        ->helperText('One month per completed year as shipped — a convention, not a statement of law. Confirm what applies where you operate.'),
                    TextInput::make('provident_fund_amount')
                        ->label(__('Provident fund'))
                        ->numeric()
                        ->default(0)
                        ->helperText('The fund\'s balance to the leaving date, from the ledger. Zero when the company runs no fund here.'),
                ])
                ->columns(3),

            Section::make('Owed back')
                ->schema([
                    TextInput::make('outstanding_advance')->label(__('Unrecovered advance'))->numeric()->default(0),
                    TextInput::make('unreturned_asset_value')->label(__('Unreturned kit'))->numeric()->default(0),
                    TextInput::make('notice_recovery')
                        ->label(__('Notice not served'))
                        ->numeric()
                        ->default(0)
                        ->helperText('Never computed for you: whether notice was served, and what to do about it, is a judgement somebody makes.'),
                    TextInput::make('other_deductions')->numeric()->default(0),
                ])
                ->columns(2),

            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.display_label')
                    ->label(__('Employee'))
                    ->searchable(query: fn ($query, string $search) => LandlordUserColumn::search($query, $search))
                    ->sortable(),

                TextColumn::make('left_on')->label(__('Left'))->date('d M Y')->sortable(),

                TextColumn::make('leave_encashment_amount')->label(__('Leave'))->money(CompanyCurrency::code())->alignEnd()->toggleable(),
                TextColumn::make('gratuity_amount')->label(__('Gratuity'))->money(CompanyCurrency::code())->alignEnd()->toggleable(),
                TextColumn::make('outstanding_advance')->label(__('Advance'))->money(CompanyCurrency::code())->alignEnd()->toggleable(),
                TextColumn::make('unreturned_asset_value')->label(__('Kit'))->money(CompanyCurrency::code())->alignEnd()->toggleable(),

                TextColumn::make('net_amount')
                    ->label(__('Net'))
                    ->money(CompanyCurrency::code())
                    ->alignEnd()
                    ->weight('bold')
                    // A settlement can legitimately be negative: somebody leaving with an
                    // unrecovered advance and an unreturned laptop may owe the company.
                    // Shown as it falls rather than clamped, because clamping quietly
                    // writes off a debt nobody decided to write off.
                    ->color(fn (FinalSettlement $record): string => $record->isOwedToCompany() ? 'danger' : 'success')
                    ->description(fn (FinalSettlement $record): ?string => $record->isOwedToCompany()
                        ? 'owed to the company'
                        : null),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        FinalSettlement::STATUS_DRAFT => 'warning',
                        FinalSettlement::STATUS_APPROVED => 'info',
                        default => 'success',
                    })
                    ->sortable(),
            ])
            ->defaultSort('left_on', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    FinalSettlement::STATUS_DRAFT => 'Draft',
                    FinalSettlement::STATUS_APPROVED => 'Approved',
                    FinalSettlement::STATUS_PAID => 'Paid',
                ]),
            ])
            ->recordActions([
                Action::make('rebuild')
                    ->label(__('Recalculate'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Gathers the figures again from leave, advances and the asset register. Only while this is a draft.')
                    ->visible(fn (FinalSettlement $record): bool => $record->isDraft()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (FinalSettlement $record): void {
                        try {
                            app(FinalSettlementBuilder::class)->build($record->employee, $record->left_on);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Recalculated from the current records.')->send();
                    }),

                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Approving records that this figure was agreed. It does NOT pay anything and posts nothing to the ledger — pay it through a payslip or a payment as usual.')
                    ->visible(fn (FinalSettlement $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->action(function (FinalSettlement $record): void {
                        $record->update([
                            'status' => FinalSettlement::STATUS_APPROVED,
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                            'net_amount' => $record->computedNet(),
                        ]);

                        Notification::make()->success()
                            ->title('Approved. Pay it through a payslip or a payment — nothing has been posted.')
                            ->send();
                    }),

                /**
                 * The way back from an approval that was wrong.
                 *
                 * `FinalSettlementBuilder` refuses to rebuild an approved settlement and tells whoever hit
                 * Recalculate to "reopen it before rebuilding" — advice with nothing behind it until now.
                 *
                 * The reason is required because this withdraws somebody's agreement to a figure: the
                 * change of status is in the audit trail either way, and "who took it back, and why" is the
                 * question asked afterwards.
                 */
                Action::make('reopen')
                    ->label(__('Reopen'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Puts the settlement back to draft so the figures can be corrected or '
                        .'recalculated. Nothing was posted when it was approved, so there is nothing to '
                        .'reverse — but the approval itself is withdrawn and has to be given again.')
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Why is it being reopened?'))
                            ->rows(2)
                            ->required()
                            ->maxLength(255),
                    ])
                    ->visible(fn (FinalSettlement $record): bool => auth()->user()?->can('reopen', $record) ?? false)
                    ->action(function (FinalSettlement $record, array $data): void {
                        $approver = $record->approved_by;

                        $record->update([
                            'status' => FinalSettlement::STATUS_DRAFT,
                            'approved_by' => null,
                            'approved_at' => null,
                        ]);

                        activity('FinalSettlement')
                            ->performedOn($record)
                            ->causedBy(auth()->user())
                            ->event('reopened')
                            ->withProperties([
                                'reason' => $data['reason'],
                                'approved_by' => $approver,
                                'net_amount' => (float) $record->net_amount,
                            ])
                            ->log("Settlement #{$record->id} reopened: {$data['reason']}");

                        Notification::make()->success()
                            ->title('Back to draft. It needs approving again once the figures are right.')
                            ->send();
                    }),

                \Filament\Actions\EditAction::make(),

                /**
                 * For the one built against the wrong person, or for a leaver who turned out to be staying.
                 *
                 * `FinalSettlementBuilder` keys on the employee, so a settlement built by mistake is not
                 * merely clutter: it is the one row that employee can ever have, and rebuilding it for the
                 * right date only edits the wrong record rather than replacing it.
                 *
                 * Drafts only, which the policy has always said and no screen has ever offered. An approved
                 * settlement is a figure somebody committed to — if one of those is wrong, it needs a way
                 * back to draft rather than a delete, and that is a decision nobody has asked for yet.
                 */
                \Filament\Actions\DeleteAction::make()
                    ->modalDescription('Deletes the settlement only. The leave, advances and issued kit it '
                        .'was gathered from are untouched, and nothing was ever posted — so there is nothing '
                        .'to reverse. You can build it again at any time.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinalSettlements::route('/'),
        ];
    }
}
