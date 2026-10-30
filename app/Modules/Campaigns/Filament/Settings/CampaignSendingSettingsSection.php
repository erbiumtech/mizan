<?php

namespace App\Modules\Campaigns\Filament\Settings;

use App\Modules\Campaigns\Services\CampaignSender;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * How fast a campaign goes out — the batch DeliverCampaign sends, and the pause before the next.
 *
 * A setting because the right pace is the company's mail and WhatsApp provider's, not this
 * application's: a shared mailbox throttles at a few hundred an hour, a transactional provider takes
 * thousands, and Meta's limits rise with the number's history. The defaults are the cautious end —
 * fifty a minute — because sending too fast is what gets a domain or a number blocked.
 */
class CampaignSendingSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'campaigns.sending';
    }

    public function components(): array
    {
        return [
            Section::make('Campaigns')
                ->description('Campaigns go out in batches on the queue. Every recipient is marked sent, failed or skipped '
                    .'as their message goes, so a send that stops part-way is resumed without anybody receiving it twice.')
                ->visible(fn (): bool => modules()->enabled('campaigns'))
                ->columns(2)
                ->schema([
                    TextInput::make('campaigns_batch_size')
                        ->label(__('Messages per batch'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000)
                        ->required()
                        ->helperText('How many recipients each batch sends to. Fifty unless your provider allows more.'),

                    TextInput::make('campaigns_batch_interval_seconds')
                        ->label(__('Pause between batches (seconds)'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(3600)
                        ->required()
                        ->helperText('Sixty by default — so fifty a minute. Raise it if your provider throttles or '
                            .'your WhatsApp number is new; zero sends the batches back to back.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'campaigns_batch_size' => CampaignSender::batchSize(),
            'campaigns_batch_interval_seconds' => CampaignSender::batchInterval(),
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('campaigns_batch_size', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);

        $settings->set('campaigns.send.batch_size', min(1000, max(1, (int) ($state['campaigns_batch_size'] ?: CampaignSender::DEFAULT_BATCH_SIZE))));
        $settings->set('campaigns.send.batch_interval_seconds', min(3600, max(0, (int) ($state['campaigns_batch_interval_seconds'] ?? CampaignSender::DEFAULT_BATCH_INTERVAL_SECONDS))));
    }
}
