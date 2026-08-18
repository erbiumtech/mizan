<?php

namespace App\Modules\Accounting\Filament\Settings;

use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Support\Contracts\SettingsSection;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;

/**
 * The company's base currency, on the Company Settings screen.
 *
 * Lived in `Core\Filament\Pages\CompanySettings`, which is how Core came to name `Currency` and
 * `JournalEntryLine` — see docs/module-packaging-plan.md §9. It moved as a whole because all of it is
 * accounting: what the list of choices is, whether the choice is still open, and what changing it would mean
 * to the amounts already stored.
 *
 * The base currency is the one field on that page that is **not** a setting. It is a row in the currencies
 * table, which is why the contract has a `save()` hook rather than a list of keys: `is_base` moves from one
 * row to another, and the model — not this screen — is what refuses the move once entries are posted.
 */
class CurrencySettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'accounting.currency';
    }

    public function components(): array
    {
        return [
            // The company's own currency, not a per-document or per-report choice:
            // it is what every amount in this company's ledger means.
            Section::make('Currency')
                ->description('What this company keeps its books in. Everything posted is recorded in it.')
                ->schema([
                    Select::make('base_currency')
                        ->label('Base currency')
                        ->options(fn (): array => static::currencyOptions())
                        ->selectablePlaceholder(false)
                        ->native(false)
                        // Fixed once anything is posted: every stored amount means
                        // this currency, so changing it would reinterpret the ledger
                        // rather than restate it.
                        ->disabled(fn (): bool => static::ledgerHasEntries())
                        ->helperText(fn (): string => static::ledgerHasEntries()
                            ? 'Fixed: entries have been posted in this currency, and changing it would reinterpret them rather than restate them.'
                            : 'Can still be changed because nothing has been posted yet.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return ['base_currency' => Currency::baseCode()];
    }

    public function save(array $state): void
    {
        $code = $state['base_currency'] ?? null;

        if (! $code || $code === Currency::baseCode()) {
            return;
        }

        try {
            Currency::where('code', $code)->firstOrFail()->update(['is_base' => true]);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /**
     * The currencies that may be chosen, and always the one in use.
     *
     * A company whose currency list has not been seeded, or whose base currency has since been switched off,
     * still has a base currency — the page shows it, so it has to be a valid answer. Leaving it out makes the
     * form reject the value it was itself given.
     *
     * @return array<string, string>
     */
    public static function currencyOptions(): array
    {
        $options = Currency::active()
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->code => $row->code.' — '.$row->name])
            ->all();

        $base = Currency::baseCode();
        $name = Currency::where('code', $base)->value('name');

        return $options + [$base => $name ? "{$base} — {$name}" : $base];
    }

    /** Has anything been posted in the current base currency? */
    public static function ledgerHasEntries(): bool
    {
        return JournalEntryLine::exists();
    }
}
