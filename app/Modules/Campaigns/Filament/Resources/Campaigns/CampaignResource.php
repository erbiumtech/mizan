<?php

namespace App\Modules\Campaigns\Filament\Resources\Campaigns;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Campaigns\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Modules\Campaigns\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Modules\Campaigns\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Campaigns\Services\CampaignSender;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;
use UnitEnum;

/**
 * Campaigns. **The one module here that can damage the company's reputation.**
 *
 * Two constraints, both external and both enforced rather than merely described:
 *
 * **WhatsApp needs a pre-approved template.** Meta's Cloud API refuses free text outside a
 * 24-hour service window, and repeated attempts risk the number.
 *
 * **Consent is checked on every send, and no record means no.** Silence is not agreement.
 * Recipients without consent are recorded as skipped, so a campaign that reached nobody is
 * distinguishable from one that was never sent.
 */
class CampaignResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),

            Select::make('channel')
                ->options([
                    Campaign::CHANNEL_EMAIL => 'Email',
                    Campaign::CHANNEL_WHATSAPP => 'WhatsApp',
                ])
                ->default(Campaign::CHANNEL_EMAIL)
                ->required()
                ->live(),

            Select::make('segment_id')
                ->label('Send to')
                ->options(fn (): array => Segment::query()->active()->pluck('name', 'id')->all())
                ->required()
                ->helperText('Resolved when you send, not when you save — so a campaign reaches everybody who matches then, not last month\'s list.'),

            TextInput::make('subject')
                ->label('Subject line')
                ->maxLength(255)
                ->required(fn (Get $get): bool => $get('channel') === Campaign::CHANNEL_EMAIL)
                ->visible(fn (Get $get): bool => $get('channel') === Campaign::CHANNEL_EMAIL),

            TextInput::make('template_name')
                ->label('Approved template')
                ->maxLength(255)
                ->required(fn (Get $get): bool => $get('channel') === Campaign::CHANNEL_WHATSAPP)
                ->visible(fn (Get $get): bool => $get('channel') === Campaign::CHANNEL_WHATSAPP)
                ->helperText('The name of a template Meta has already approved. Free text cannot be sent on WhatsApp outside a 24-hour service window — the API refuses it, and repeated attempts put your number at risk.'),

            Textarea::make('body')
                ->rows(6)
                ->columnSpanFull()
                ->helperText(fn (Get $get): string => $get('channel') === Campaign::CHANNEL_WHATSAPP
                    ? 'Variables for the template, not the message itself.'
                    : 'The email body.'),

            DateTimePicker::make('scheduled_at')->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('channel')
                    ->badge()
                    ->color(fn (string $state): string => $state === Campaign::CHANNEL_WHATSAPP ? 'success' : 'info')
                    ->description(fn (Campaign $record): ?string => $record->isWhatsApp()
                        ? 'template: '.$record->template_name
                        : null),

                TextColumn::make('segment.name')->label('To')->placeholder('—'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Campaign::STATUS_SENT => 'success',
                        Campaign::STATUS_SENDING => 'warning',
                        Campaign::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                // The skipped figure is shown as its own number rather than folded into
                // failures: a skip is the system working correctly, and burying it in a failure
                // count would make somebody try to "fix" it.
                TextColumn::make('outcome')
                    ->label('Result')
                    ->state(function (Campaign $record): string {
                        $outcome = $record->outcome();

                        if (array_sum($outcome) === 0) {
                            return '—';
                        }

                        return "{$outcome['sent']} sent, {$outcome['skipped']} skipped for consent"
                            .($outcome['failed'] > 0 ? ", {$outcome['failed']} failed" : '');
                    })
                    ->wrap(),

                TextColumn::make('sent_at')->label('Sent')->dateTime('d M Y H:i')->placeholder('—')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    Campaign::STATUS_DRAFT => 'Draft',
                    Campaign::STATUS_SCHEDULED => 'Scheduled',
                    Campaign::STATUS_SENT => 'Sent',
                    Campaign::STATUS_CANCELLED => 'Cancelled',
                ]),

                SelectFilter::make('channel')->options([
                    Campaign::CHANNEL_EMAIL => 'Email',
                    Campaign::CHANNEL_WHATSAPP => 'WhatsApp',
                ]),
            ])
            ->recordActions([
                /**
                 * The dry run, and the reason it exists separately.
                 *
                 * On a channel that can cost you your number, seeing exactly who a campaign
                 * would reach — and who it would skip, and why — before anything leaves the
                 * building is not a nicety.
                 */
                Action::make('prepare')
                    ->label('Check the audience')
                    ->icon('heroicon-o-users')
                    ->color('gray')
                    ->visible(fn (Campaign $record): bool => $record->isSendable()
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Campaign $record): void {
                        try {
                            $result = app(CampaignSender::class)->prepare($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title("{$result['permitted']} would be sent to.")
                            ->body("{$result['skipped']} have no consent on record for this channel and will be skipped. Nothing has been sent.")
                            ->persistent()
                            ->send();
                    }),

                Action::make('send')
                    ->label('Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Send this campaign?')
                    ->modalDescription('This reaches people outside your company and cannot be undone. Consent is re-checked for every recipient as it goes, so anybody who unsubscribed since you checked the audience is skipped.')
                    ->visible(fn (Campaign $record): bool => auth()->user()?->can('send', $record) ?? false)
                    ->action(function (Campaign $record): void {
                        try {
                            $result = app(CampaignSender::class)->send($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title("Sent to {$result['sent']}.")
                            ->body("{$result['skipped']} skipped for consent.")
                            ->send();
                    }),

                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
