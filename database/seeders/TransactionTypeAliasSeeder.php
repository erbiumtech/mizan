<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Accounting\Models\TransactionTypeAlias;
use Illuminate\Database\Seeder;

/**
 * The words people actually use for the shipped categories — `docs/ai-command-bot-plan.md` §3.2.
 *
 * Two spellings for each: the Urdu script somebody dictates, and the roman-Urdu most people type on a
 * phone keyboard. The roman half matters more in practice — an Urdu keyboard is a deliberate choice, and
 * "bijli ka bill" typed in Latin letters is the ordinary case.
 *
 * **Keyed by category code, and silently skipped when that code is absent.** A tenant on the personal
 * chart has no `office-supplies`, and a tenant who deleted a category should not have it resurrected as a
 * side effect of seeding aliases. This adds words for categories that exist; it never creates one.
 *
 * Additive and idempotent: `firstOrCreate` on the alias, so a tenant's own additions survive a re-run and
 * a second run adds nothing. That is the same discipline the chart seeders were corrected to — reference
 * data a company may have edited is not ours to rewrite.
 */
class TransactionTypeAliasSeeder extends Seeder
{
    /**
     * category code => [alias => locale]
     *
     * Deliberately not exhaustive. §9's `unresolved()` scope is how the real list gets found: an utterance
     * that failed to resolve is the evidence for the alias that should have existed, and guessing a
     * hundred words up front produces a list nobody can maintain and false matches nobody predicted.
     *
     * @var array<string, array<string, string>>
     */
    private const ALIASES = [
        'rent' => ['kiraya' => 'ur-Latn', 'kraya' => 'ur-Latn', 'کرایہ' => 'ur'],
        'food' => ['khana' => 'ur-Latn', 'grocery' => 'en', 'groceries' => 'en', 'کھانا' => 'ur', 'راشن' => 'ur'],
        'utilities' => ['bijli' => 'ur-Latn', 'gas bill' => 'ur-Latn', 'بجلی' => 'ur', 'گیس' => 'ur'],
        'fuel' => ['petrol' => 'en', 'diesel' => 'en', 'پٹرول' => 'ur'],
        'transport' => ['petrol' => 'en', 'kiraya gaari' => 'ur-Latn', 'سفر' => 'ur'],
        'medical' => ['dawai' => 'ur-Latn', 'doctor' => 'en', 'دوائی' => 'ur', 'علاج' => 'ur'],
        'salary' => ['tankhwah' => 'ur-Latn', 'تنخواہ' => 'ur'],
        'education' => ['fees' => 'en', 'school fee' => 'en', 'تعلیم' => 'ur', 'فیس' => 'ur'],
        'domestic-staff' => ['maid' => 'en', 'naukar' => 'ur-Latn', 'ملازم' => 'ur'],
        'household' => ['marammat' => 'ur-Latn', 'repair' => 'en', 'مرمت' => 'ur'],
        'family' => ['gift' => 'en', 'tohfa' => 'ur-Latn', 'تحفہ' => 'ur'],
        'cleaning' => ['safai' => 'ur-Latn', 'صفائی' => 'ur'],
        'rental-income' => ['kiraya aaya' => 'ur-Latn', 'rent received' => 'en'],
        'service-revenue' => ['fee received' => 'en', 'consulting' => 'en'],
        'sales-revenue' => ['sale' => 'en', 'bikri' => 'ur-Latn', 'فروخت' => 'ur'],

        /*
         * The bare word for money coming in.
         *
         * "income 500000 in" resolved to nothing, because every receipt category was named for a *kind*
         * of receipt — service revenue, sales revenue — and nobody types the kind. This is not the
         * catch-all the parser refuses to fall back to: `other-income` is defined in
         * TransactionTypeSeeder as "receipts with no more specific category", so an unqualified word
         * mapping to it is the honest match rather than a guess. Naming a kind still wins, because the
         * longest alias is matched first.
         *
         * **Direction words are deliberately absent from this list.** "received" reads like an obvious
         * alias for a receipt category and would be a bad one: it is also a direction word, it is longer
         * than most category names, and longest-match-first means "salary 50000 received" would file
         * against other-income instead of salary. A word that already carries one meaning to the parser
         * must not be taught a second.
         */
        'other-income' => [
            'income' => 'en',
            'aamdani' => 'ur-Latn', 'amdani' => 'ur-Latn', 'آمدنی' => 'ur',
        ],
    ];

    public function run(): void
    {
        $types = TransactionType::query()->pluck('id', 'code');
        $added = 0;

        foreach (self::ALIASES as $code => $aliases) {
            $typeId = $types[$code] ?? null;

            if ($typeId === null) {
                continue;
            }

            foreach ($aliases as $alias => $locale) {
                /*
                 * `alias` is unique across the tenant, and two categories in this list genuinely share a
                 * word — "petrol" is fuel for a business and transport for a household, and only one of
                 * those categories exists in any given tenant. Where both somehow do, first wins and the
                 * second is skipped rather than throwing: a duplicate alias is a nuance, not a failure to
                 * seed a company over.
                 */
                if (TransactionTypeAlias::where('alias', mb_strtolower($alias))->exists()) {
                    continue;
                }

                TransactionTypeAlias::create([
                    'transaction_type_id' => $typeId,
                    'alias' => $alias,
                    'locale' => $locale,
                ]);

                $added++;
            }
        }

        $this->command?->info("Seeded {$added} category aliases.");
    }
}
