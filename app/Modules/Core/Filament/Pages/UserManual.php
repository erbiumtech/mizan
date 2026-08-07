<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The whole application as one readable document, start to finish.
 *
 * Distinct from the Help action on each screen, and deliberately so. Those
 * answer "what is this field" for somebody already on the right page; nothing
 * in them answers "how does a salary get from an employee record to money in
 * a bank account", because that crosses six screens and three modules and no
 * single screen owns it. This is where those journeys live — each chapter a
 * walkthrough in the order the work actually happens, naming who approves what
 * and what can no longer be undone.
 *
 * Rendered as one long page rather than a chapter per URL: it is written to be
 * read through by somebody new, the cross-references between chapters are
 * constant, and one page is what makes Ctrl-P produce a manual you can hand to
 * a new hire.
 *
 * Every chapter is shown to everybody who can reach the panel, and is NOT
 * filtered by licensed module or role. That is a choice: the chapters reference
 * each other continuously, so hiding one leaves the others pointing at nothing,
 * and knowing how approval works is useful to the person waiting on it as well
 * as the person doing it. Chapters say which module and permission they need.
 */
class UserManual extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.user-manual';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $title = 'User Manual';

    /** Directly below Reports, because a manual nobody finds is a file nobody reads. */
    protected static ?int $navigationSort = 0;

    /**
     * The chapters, in reading order, as file slug => title.
     *
     * Order and titles live here rather than being derived from the filenames or
     * a heading inside each file: the numeric prefixes exist to keep the reading
     * order stable, and they are not something to show a reader.
     *
     * UserManualTest fails if this list and the files on disk disagree in either
     * direction, so a chapter cannot be added to the directory and silently go
     * unpublished, and a renamed file cannot leave a gap in the manual.
     *
     * @var array<string, string>
     */
    public const CHAPTERS = [
        '01-getting-started' => 'Setting up a company',
        '02-people-and-access' => 'People, roles and what they can do',
        '03-payroll' => 'Running payroll: from employee to money in the bank',
        '04-advances-and-expense-claims' => 'Advances and expense claims',
        '05-payments' => 'Paying suppliers and other beneficiaries',
        '06-petty-cash' => 'Petty cash',
        '07-invoicing' => 'Invoicing a customer and getting paid',
        '08-billing-runs' => 'Monthly billing runs',
        '09-ledger' => 'The ledger: what posts, when, and how to correct it',
        '10-assets-and-stock' => 'Fixed assets and stock',
        '11-reconciliation-and-close' => 'Reconciling the bank and closing the period',
        '12-reports' => 'Reports and statutory output',
        '13-personal-finance' => 'Your own books: personal finance and tax',
        '14-budgeting' => 'Budgeting: planning a year and measuring against it',
    ];

    public static function canAccess(): bool
    {
        // Core is always available, so this is really "is anybody signed in" —
        // documentation is not a permission-bearing resource. Each chapter still
        // states the permission the work it describes requires.
        return static::moduleIsAvailable() && auth()->check();
    }

    /**
     * The chapters that actually have a file, as anchor => [title, html].
     *
     * A chapter listed above with no file on disk is skipped rather than fatal:
     * UserManualTest is what reports the mismatch, and a half-written manual
     * should still open.
     *
     * @return array<string, array{title: string, html: string}>
     */
    public function chapters(): array
    {
        $chapters = [];

        foreach (self::CHAPTERS as $slug => $title) {
            $path = static::pathFor($slug);

            if (! File::exists($path)) {
                continue;
            }

            $chapters[static::anchorFor($slug)] = [
                'title' => $title,
                'html' => Str::markdown(File::get($path)),
            ];
        }

        return $chapters;
    }

    public static function pathFor(string $slug): string
    {
        return resource_path("markdown/manual/{$slug}.md");
    }

    /** Anchor without the ordering prefix, so a link survives a chapter moving. */
    public static function anchorFor(string $slug): string
    {
        return Str::slug(preg_replace('/^\d+-/', '', $slug));
    }
}
