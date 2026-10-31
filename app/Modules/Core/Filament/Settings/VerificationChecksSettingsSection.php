<?php

namespace App\Modules\Core\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use App\Support\VerificationChecks;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Section;

/**
 * What somebody has to have confirmed before they are let in — docs/saas-launch-plan.md §14.8.
 *
 * **The list is whatever the modules registered and the company can actually do.** A
 * check with no means behind it — phone codes with no WhatsApp provider, an address
 * letter nobody has written — is not shown at all, because a switch that cannot take
 * effect is worse than no switch: somebody turns it on, believes it, and finds out
 * when an audit asks.
 *
 * Stored keys that are no longer offered are kept rather than dropped on save. A
 * company whose WhatsApp credentials lapse for a week should find its phone check
 * still switched on when they come back, not quietly forgotten.
 */
class VerificationChecksSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'verification.checks';
    }

    public function components(): array
    {
        $offered = VerificationChecks::offered();

        return [
            Section::make('Before somebody is let in')
                ->description($offered === []
                    ? 'Nothing can be checked on this installation yet. A phone check needs a WhatsApp provider; '
                        .'an address check needs an address verification letter template; a document check needs '
                        .'some documents to be required.'
                    : 'Tick what somebody must have confirmed. Until they have, every screen sends them to '
                        .'Confirm Your Details — they can still sign in, which is the point, because that is where '
                        .'they finish it.')
                ->schema($offered === [] ? [] : [
                    CheckboxList::make('verification_required')
                        ->label(__('Required of every employee'))
                        ->options($offered)
                        ->descriptions(array_intersect_key(VerificationChecks::help(), $offered))
                        ->columns(1),
                ]),
        ];
    }

    public function fill(): array
    {
        $stored = setting('verification.required', []);

        return ['verification_required' => is_array($stored) ? $stored : []];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('verification_required', $state)) {
            return;
        }

        $offered = array_keys(VerificationChecks::offered());
        $stored = setting('verification.required', []);
        $stored = is_array($stored) ? $stored : [];

        // Everything the screen could not show is carried through untouched; of what it
        // could show, what was ticked.
        $kept = array_diff($stored, $offered);
        $chosen = array_intersect($offered, (array) ($state['verification_required'] ?? []));

        app(TenantSettings::class)->set('verification.required', array_values(array_unique([...$kept, ...$chosen])));
    }
}
