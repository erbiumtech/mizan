<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Pages\AccountRegister;
use App\Modules\Accounting\Filament\Pages\BalanceSheet;
use App\Modules\Accounting\Filament\Pages\BankPaymentFile;
use App\Modules\Accounting\Filament\Pages\BudgetVsActual;
use App\Modules\Accounting\Filament\Pages\CashFlow;
use App\Modules\Accounting\Filament\Pages\ContractorPayments;
use App\Modules\Accounting\Filament\Pages\CurrencyRevaluation;
use App\Modules\Accounting\Filament\Pages\FindTransactions;
use App\Modules\Accounting\Filament\Pages\PettyCashBook;
use App\Modules\Accounting\Filament\Pages\ProfitAndLoss;
use App\Modules\Accounting\Filament\Pages\TrialBalance;
use App\Modules\Invoicing\Filament\Pages\AgedPayables;
use App\Modules\Invoicing\Filament\Pages\AgedReceivables;
use App\Modules\Payroll\Filament\Pages\FbrTaxFile;
use App\Modules\Payroll\Filament\Pages\SalaryBankFile;
use App\Modules\Payroll\Filament\Pages\TaxSummary;
use BackedEnum;
use Filament\Pages\Page;

/**
 * One door to every report.
 *
 * The sidebar used to carry a Reports group of fourteen entries — the
 * statements, the ageing, the payroll and bank files and two interactive
 * ledgers, all at one level with nothing to say which was which. They are all
 * still here, grouped and described, behind a single link.
 *
 * Each link is filtered through the owning page's own canAccess(), so this page
 * never offers a report that would refuse to open: not one whose module the
 * company has not licensed, and not one the role has no permission for. When
 * that leaves nothing, the page itself disappears from the sidebar rather than
 * greeting somebody with an empty screen.
 *
 * Lives in Core, not Accounting, because it spans four modules — and Core is the
 * one module that is always on, which is what lets the per-link gates above be
 * the only thing deciding what appears.
 */
class Reports extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $title = 'Reports';

    /**
     * Top level, immediately below the Dashboard (which is -2) and above every
     * group. Deliberately not in a group of its own: a group named Reports
     * holding a single item named Reports says the same thing twice.
     */
    protected static ?int $navigationSort = -1;

    /**
     * What the page links to, as heading => [page class => what it answers].
     *
     * The descriptions are the point of the page. A list of fourteen titles is
     * what the sidebar already was; saying what each one tells you is what makes
     * the choice possible without opening all of them.
     *
     * Adding a report means adding it here. ReportsHubTest fails on a page that
     * is hidden from the sidebar and missing from this list — otherwise such a
     * page is reachable by nothing but the ⌘K palette and its own URL.
     *
     * @var array<string, array<class-string, string>>
     */
    private const SECTIONS = [
        'Financial statements' => [
            BalanceSheet::class => 'What the company owns, owes and is worth, on a date.',
            ProfitAndLoss::class => 'Income less expenses over a period, and the profit that leaves.',
            CashFlow::class => 'Where the money actually came from and went, period by period.',
            TrialBalance::class => 'Every account with its balance, and the proof that the books add up.',
            BudgetVsActual::class => 'What was planned against what was spent, by account and by month.',
        ],
        'Receivables & payables' => [
            AgedReceivables::class => 'What customers owe, bucketed by how late it is.',
            AgedPayables::class => 'What the company owes suppliers, bucketed by how late it is.',
            ContractorPayments::class => 'What each contractor has been paid, and over what period.',
        ],
        'Payroll & tax' => [
            TaxSummary::class => 'Tax withheld per employee for the year, with the slab it fell in.',
            FbrTaxFile::class => 'The withholding statement, in the format FBR accepts.',
            SalaryBankFile::class => 'Salary payments as a bank upload file, for a payroll month.',
        ],
        'Ledgers & books' => [
            AccountRegister::class => 'One account, every transaction against it, running balance — and edits.',
            FindTransactions::class => 'Search the whole ledger by account, date, amount or wording.',
            PettyCashBook::class => 'The cash float: what was spent, what is left, and replenishment.',
            CurrencyRevaluation::class => 'Foreign balances at the rate on a date, and the difference posted.',
        ],
        // GnuCash Import used to sit here. It is an import, not a report, and now
        // lives in Settings beside Import from CSV — which is why this section is
        // down to bank files alone.
        'Bank files' => [
            BankPaymentFile::class => 'Selected payments as a bank transfer file.',
        ],
    ];

    /**
     * Every page this hub links to, ungrouped.
     *
     * @return array<int, class-string>
     */
    public static function linkedPages(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::SECTIONS)));
    }

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // Whether *any* report would open. Deliberately not sections(): that
        // builds a URL per link, and this runs while the sidebar is assembled on
        // every request.
        foreach (static::linkedPages() as $page) {
            if ($page::canAccess()) {
                return true;
            }
        }

        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('reports', 'Reports: Help'),
        ];
    }

    /**
     * The links to render, with empty sections dropped.
     *
     * Static so that canAccess() above and the view below ask the same question
     * of the same list; labels and icons come from each page rather than being
     * repeated here, so renaming a report renames its link.
     *
     * @return array<string, array<int, array{label: string, description: string, url: string, icon: string|BackedEnum|null}>>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (self::SECTIONS as $heading => $pages) {
            $links = [];

            foreach ($pages as $page => $description) {
                if (! $page::canAccess()) {
                    continue;
                }

                $links[] = [
                    'label' => (string) $page::getNavigationLabel(),
                    'description' => $description,
                    'url' => $page::getUrl(),
                    'icon' => $page::getNavigationIcon(),
                ];
            }

            if ($links !== []) {
                $sections[$heading] = $links;
            }
        }

        return $sections;
    }
}
