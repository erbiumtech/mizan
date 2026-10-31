<?php

namespace App\Modules\Campaigns\Filament\RelationManagers;

use App\Modules\Campaigns\Models\Consent;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Consent on a lead or a customer, recorded and withdrawn where the person is — docs/crms-plan.md §6.
 *
 * Append-only, as the register is: each action writes a new row and nothing is edited or deleted, so
 * "who agreed to this, and when" stays answerable after somebody changes their mind. Offered on the
 * lead and the customer by this module (CampaignsServiceProvider), so neither of theirs knows of it.
 */
class ConsentsRelationManager extends RelationManager
{
    protected static string $relationship = 'consents';

    protected static ?string $title = 'Consent';

    private const CHANNELS = [Consent::CHANNEL_EMAIL => 'Email', Consent::CHANNEL_WHATSAPP => 'WhatsApp'];

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return modules()->enabled('campaigns') && (auth()->user()?->can('CampaignView') ?? false);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('recorder'))
            ->columns([
                TextColumn::make('recorded_at')->label(__('When'))->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('channel')->formatStateUsing(fn (string $state): string => self::CHANNELS[$state] ?? $state),
                TextColumn::make('state')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Consent::STATE_GRANTED ? 'Agreed' : 'Withdrawn')
                    ->color(fn (string $state): string => $state === Consent::STATE_GRANTED ? 'success' : 'danger'),
                TextColumn::make('source')->placeholder('—')->wrap(),
                TextColumn::make('recorder.name')->label(__('Recorded by'))->placeholder('—'),
            ])
            ->defaultSort('recorded_at', 'desc')
            ->headerActions([
                $this->recordAction('grant', 'Record consent', 'heroicon-o-check-circle', 'success', Consent::STATE_GRANTED,
                    'Where they agreed: a signed form, the website, a reply to an email.'),
                $this->recordAction('revoke', 'Record withdrawal', 'heroicon-o-no-symbol', 'danger', Consent::STATE_REVOKED,
                    'How they asked to stop: replied STOP, asked on a call.'),
            ]);
    }

    private function recordAction(string $name, string $label, string $icon, string $color, string $state, string $sourceHelp): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (): bool => auth()->user()?->can('create', Consent::class) ?? false)
            ->schema([
                Select::make('channel')->options(self::CHANNELS)->required(),
                TextInput::make('source')->label(__('Evidence'))->required()->maxLength(190)->helperText($sourceHelp),
            ])
            ->action(function (array $data) use ($state): void {
                $subject = $this->getOwnerRecord();

                $state === Consent::STATE_GRANTED
                    ? Consent::grant($subject, $data['channel'], $data['source'])
                    : Consent::revoke($subject, $data['channel'], $data['source']);

                Notification::make()->success()->title('Recorded. Campaigns check this before every message.')->send();
            });
    }
}
