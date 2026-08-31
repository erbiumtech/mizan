<?php

namespace App\Modules\Accounting\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Section;

/**
 * The date before which nothing may be posted — `docs/erpnext-gap-plan.md` Phase 3.
 *
 * Between "the year is open" and "the year is closed" there was nothing, so the ordinary month-end request
 * — *stop backdating into July now that July is reported* — could not be expressed at all. This is one date
 * and one guard in `JournalEntryService::post()`.
 *
 * **Deliberately not ERPNext's other mechanism.** Theirs also has an Accounting Period that blocks selected
 * *document types* over a range, which is a permission matrix crossed with a calendar. Nobody here has asked
 * for that, and a frozen date answers the question people actually ask.
 *
 * The exemption is a permission rather than a second setting: `JournalEntryBackdate`, held by nobody below
 * Administrator, so a correction is possible and attributable instead of requiring the date to be cleared
 * and — the part that goes wrong — set back afterwards.
 */
class LedgerFreezeSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'accounting.ledger-freeze';
    }

    public function components(): array
    {
        return [
            Section::make('Closing off a period')
                ->description('Stop entries being posted into months that have already been reported, while the year stays open.')
                ->schema([
                    DatePicker::make('accounting.ledger_frozen_before')
                        ->label('Freeze the ledger before')
                        ->native(false)
                        ->helperText('Nothing dated earlier can be posted. Drafts can still be written and re-dated, '
                            .'and a holder of JournalEntryBackdate can still post — the activity log records who did. '
                            .'Leave empty for no freeze; a closed fiscal year is refused regardless.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return ['accounting.ledger_frozen_before' => setting('accounting.ledger_frozen_before')];
    }

    public function save(array $state): void
    {
        app(\App\Support\TenantSettings::class)->set(
            'accounting.ledger_frozen_before',
            blank($state['accounting.ledger_frozen_before'] ?? null)
                ? null
                : \Illuminate\Support\Carbon::parse($state['accounting.ledger_frozen_before'])->toDateString(),
        );
    }
}
