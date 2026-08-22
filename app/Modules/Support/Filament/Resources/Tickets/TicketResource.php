<?php

namespace App\Modules\Support\Filament\Resources\Tickets;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Models\Employee;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Projects\Models\Project;
use App\Modules\Support\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Modules\Support\Filament\Resources\Tickets\Pages\EditTicket;
use App\Modules\Support\Filament\Resources\Tickets\Pages\ListTickets;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketCategory;
use App\Modules\Support\Services\TicketService;
use App\Support\NavigationBadge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use UnitEnum;

/**
 * Tickets.
 *
 * **The SLA is measured, never enforced.** A breach shows on the row and in the report; nothing
 * refuses to close a ticket because time elapsed. The elapsed time is a fact about the past, and
 * blocking the close would make the register wrong as well as late.
 *
 * **An internal reply never starts the response clock.** Otherwise a company could meet its
 * commitment by writing a note to itself, which is exactly the failure a measured SLA exists to
 * make visible.
 */
class TicketResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $recordTitleAttribute = 'subject';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        return NavigationBadge::of(static::class, fn (): int => static::getEloquentQuery()->open()->count());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('subject')->required()->maxLength(255),

            Select::make('category_id')
                ->label('Category')
                ->options(fn (): array => TicketCategory::query()->active()->pluck('name', 'id')->all())
                ->helperText('Carries the SLA commitment and the default priority.')
                ->live(),

            // Guarded: `support` requires nothing, so a company without Invoicing records the
            // customer's name in the subject and leaves this empty.
            Select::make('contact_id')
                ->label('Customer')
                ->options(fn (): array => Contact::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (): bool => modules()->enabled('invoicing')),

            Select::make('project_id')
                ->label('Project')
                ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (): bool => modules()->enabled('projects')),

            Select::make('channel')
                ->options(array_combine(Ticket::CHANNELS, array_map(
                    fn (string $c): string => ucfirst(str_replace('_', ' ', $c)),
                    Ticket::CHANNELS,
                )))
                ->default('panel')
                ->required()
                ->helperText('How the customer actually asked. Inbound email is not parsed into tickets — that needs a mail subsystem this application does not have.'),

            Select::make('priority')
                ->options(array_combine(Ticket::PRIORITIES, array_map('ucfirst', Ticket::PRIORITIES)))
                ->default('normal')
                ->required(),

            Select::make('assignee_employee_id')
                ->label('Assigned to')
                ->options(fn (): array => Employee::query()
                    ->where('is_active', true)
                    ->get()
                    ->mapWithKeys(fn (Employee $e): array => [$e->id => $e->display_label])
                    ->all())
                ->searchable()
                ->visible(fn (): bool => modules()->enabled('employees')),

            Textarea::make('description')->rows(4)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),

                TextColumn::make('subject')
                    ->searchable()
                    ->wrap()
                    ->description(fn (Ticket $record): ?string => $record->contact?->name),

                TextColumn::make('category.name')->label('Category')->placeholder('—')->toggleable(),

                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'low' => 'gray',
                        default => 'info',
                    })
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED => 'success',
                        Ticket::STATUS_PENDING_CUSTOMER => 'warning',
                        Ticket::STATUS_NEW => 'danger',
                        default => 'info',
                    })
                    ->description(fn (Ticket $record): ?string => $record->reopened_count > 0
                        ? "reopened {$record->reopened_count}×"
                        : null)
                    ->sortable(),

                // Reported, and said to be reported. Nothing is blocked by it.
                TextColumn::make('sla')
                    ->label('SLA')
                    ->state(fn (Ticket $record): string => $record->slaNote() ?? 'within commitment')
                    ->color(fn (Ticket $record): string => $record->slaNote() ? 'danger' : 'success')
                    ->wrap(),

                TextColumn::make('opened_at')->label('Opened')->dateTime('d M Y H:i')->sortable()->toggleable(),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    Ticket::STATUS_NEW => 'New',
                    Ticket::STATUS_OPEN => 'Open',
                    Ticket::STATUS_PENDING_CUSTOMER => 'Waiting on the customer',
                    Ticket::STATUS_RESOLVED => 'Resolved',
                    Ticket::STATUS_CLOSED => 'Closed',
                ]),

                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => TicketCategory::pluck('name', 'id')->all()),

                Filter::make('open')
                    ->label('Open')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->open()),
            ])
            ->recordActions([
                Action::make('reply')
                    ->label('Reply')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('info')
                    ->visible(fn (Ticket $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->schema([
                        Textarea::make('body')->label('Reply')->required()->rows(4),

                        Toggle::make('is_internal')
                            ->label('Internal note — not for the customer')
                            ->helperText('An internal note does NOT start the response clock: a company must not be able to meet its commitment by writing to itself. It is also never shown to a customer, which matters the day a portal exists.'),
                    ])
                    ->action(function (Ticket $record, array $data): void {
                        try {
                            app(TicketService::class)->reply(
                                $record,
                                $data['body'],
                                auth()->user(),
                                (bool) ($data['is_internal'] ?? false),
                            );
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Replied.')->send();
                    }),

                Action::make('resolve')
                    ->label('Resolved')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Recorded even if the SLA was missed — the breach is reported, and refusing to record the fix would make the register wrong as well as late.')
                    ->visible(fn (Ticket $record): bool => $record->isOpen()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Ticket $record): void {
                        app(TicketService::class)->resolve($record);

                        Notification::make()->success()->title('Resolved.')->send();
                    }),

                Action::make('reopen')
                    ->label('Reopen')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Ticket $record): bool => ! $record->isOpen()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Ticket $record): void {
                        app(TicketService::class)->reopen($record);

                        Notification::make()->success()
                            ->title('Reopened.')
                            ->body('The original first-response time is kept — that is what the customer experienced.')
                            ->send();
                    }),

                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'edit' => EditTicket::route('/{record}/edit'),
        ];
    }
}
