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
use App\Modules\Invoicing\Filament\Pages\FbrInvoiceReporting;
use App\Modules\Payroll\Filament\Pages\FbrTaxFile;
use App\Modules\Payroll\Filament\Pages\SalaryBankFile;
use App\Modules\Payroll\Filament\Pages\TaxSummary;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

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
        // Separate from "Payroll & tax", which is where the withholding statement
        // lives: that one is a payroll report a human downloads and uploads, and
        // this one watches an invoice integration that reports on its own. Filing
        // them together would suggest they work the same way, and the difference
        // between a pull and a push is the whole reason this report exists.
        'Statutory reporting' => [
            FbrInvoiceReporting::class => 'Invoices FBR has not accepted, and issued invoices it never received.',
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

    // ------------------------------------------------------------ the 3a screen

    /**
     * Which section is showing. Null is all of them.
     *
     * In the query string rather than in component state alone, so that a filtered view can be
     * linked to and lands filtered — "the payroll ones" is a thing people send each other. #[Url]
     * also means the browser's back button steps back through the filters, which is what a person
     * expects from something that changes what is on screen.
     */
    #[Url]
    public ?string $section = null;

    /** Grid or list. Kept in the URL for the same reason, and because it is a lasting preference. */
    #[Url]
    public string $display = 'grid';

    /** The filter box. Deliberately not in the URL: a half-typed word is not a place to return to. */
    public string $query = '';

    /** The report whose panel is open, by key. */
    public ?string $selected = null;

    /**
     * Every report as one flat list, with a key.
     *
     * Keyed on the class basename rather than the label: the key travels in the URL and in
     * wire:click, and renaming a report's title should not break a link somebody kept.
     *
     * @return array<string, array{key: string, label: string, description: string, url: string, icon: string|BackedEnum|null, section: string}>
     */
    public static function catalogue(): array
    {
        $catalogue = [];

        foreach (static::sections() as $heading => $links) {
            foreach ($links as $link) {
                $catalogue[$link['key']] = $link + ['section' => $heading];
            }
        }

        return $catalogue;
    }

    /**
     * The section filter, once it has been checked against the sections that exist.
     *
     * `section` arrives from the query string, so it can say anything. An unrecognised value is
     * treated as no filter rather than as a filter that matches nothing: a section this company has
     * lost — payroll unlicensed, a role without the permission — is the ordinary way to arrive here
     * with a stale link, and answering it with an empty screen reads as "the reports are gone".
     */
    public function currentSection(): ?string
    {
        return array_key_exists((string) $this->section, static::sections())
            ? $this->section
            : null;
    }

    /**
     * The sections to draw, after the section filter and the search box.
     *
     * Search covers the description as well as the title, which is the point of having written
     * descriptions: "how late" finds the two ageing reports without knowing they are called ageing.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function visibleSections(): array
    {
        $query = trim(mb_strtolower($this->query));
        $section = $this->currentSection();
        $visible = [];

        foreach (static::sections() as $heading => $links) {
            if (filled($section) && $section !== $heading) {
                continue;
            }

            $matching = array_values(array_filter($links, function (array $link) use ($query): bool {
                if ($query === '') {
                    return true;
                }

                return str_contains(mb_strtolower($link['label']), $query)
                    || str_contains(mb_strtolower($link['description']), $query);
            }));

            if ($matching !== []) {
                $visible[$heading] = $matching;
            }
        }

        return $visible;
    }

    /**
     * Flat, for the list view — the same set the grid shows, without the headings.
     *
     * The section travels with each row rather than being implied by position: the list view has a
     * Category column, which is what it shows there.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleReports(): array
    {
        $reports = [];

        foreach ($this->visibleSections() as $heading => $links) {
            foreach ($links as $link) {
                $reports[] = $link + ['section' => $heading];
            }
        }

        return $reports;
    }

    /**
     * Section name => how many reports in it, for the column.
     *
     * Counted before the search filter: a category showing 0 while you type is noise, and the
     * counts are there to say how big each section is, not how many matched.
     *
     * @return array<string, int>
     */
    public static function sectionCounts(): array
    {
        return array_map('count', static::sections());
    }

    public static function total(): int
    {
        return array_sum(static::sectionCounts());
    }

    /** @return array<string, mixed>|null */
    public function selectedReport(): ?array
    {
        return static::catalogue()[$this->selected] ?? null;
    }

    public function select(string $key): void
    {
        // Only a key that is actually on offer. `selected` arrives from the browser, and the panel
        // it opens carries a link to the report — so an unfiltered value here would be a way to
        // have this page render a URL for a report the role cannot open.
        $this->selected = array_key_exists($key, static::catalogue()) ? $key : null;
    }

    public function deselect(): void
    {
        $this->selected = null;
    }

    public function updatedQuery(): void
    {
        // A filter that hides the open report should not leave its panel open over the results.
        $this->deselect();
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
     * The heading follows the filter, so the page says what it is showing.
     *
     * Filament's own header carries it rather than the view drawing a second one: that keeps one
     * title on the page, in the place every other page in this panel puts it, and leaves the search
     * and the grid/list toggle to sit beside it through PAGE_HEADER_ACTIONS_BEFORE — which is 3a's
     * single header row.
     */
    public function getHeading(): string
    {
        return $this->currentSection() ?? 'All reports';
    }

    public function getSubheading(): ?string
    {
        $showing = count($this->visibleReports());
        $total = static::total();

        if ($showing === $total) {
            return trans_choice(':count report|:count reports', $total);
        }

        return "Showing {$showing} of {$total}";
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
                    // Stable across a rename of the title, because it travels in the URL. See
                    // catalogue().
                    'key' => class_basename($page),
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
