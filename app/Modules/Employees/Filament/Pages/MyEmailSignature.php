<?php

namespace App\Modules\Employees\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmailSignature;
use App\Support\EmployeeAccess;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * An email signature out of the record — docs/saas-launch-plan.md §14.10.
 *
 * Each person's own. The point is not that it saves typing: it is that forty people's
 * signatures then say the same company name, spelt the same way, with the same
 * address, and change together when the company moves.
 */
class MyEmailSignature extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.my-email-signature';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-at-symbol';

    /*
     * No group, and that is what puts it in Home — see the module manifest.
     *
     * It is a screen about the person looking at it, which is the reason NavigationDomains
     * gives for Personal being in Home rather than in People: People is where you go to act
     * on somebody else's record.
     */
    protected static ?string $title = 'My Email Signature';

    protected static ?int $navigationSort = 64;

    public static function canAccess(): bool
    {
        // Through the request's memo — see EmployeeAccess::employeeFor(). Filament calls
        // this while building the navigation on every page, and it used to be its own
        // query each time.
        return static::moduleIsAvailable() && app(EmployeeAccess::class)->isEmployee(auth()->user());
    }

    public function employee(): ?Employee
    {
        $employee = app(EmployeeAccess::class)->employeeFor(auth()->user());
        $employee?->loadMissing('user');

        return $employee;
    }

    /** The rendered signature, for the preview. */
    public function preview(): HtmlString
    {
        $employee = $this->employee();

        return new HtmlString($employee === null ? '' : app(EmailSignature::class)->html($employee));
    }

    /** The same markup as text, for pasting into a mail client's HTML box. */
    public function markup(): string
    {
        $employee = $this->employee();

        return $employee === null ? '' : app(EmailSignature::class)->html($employee);
    }

    public function plain(): string
    {
        $employee = $this->employee();

        return $employee === null ? '' : app(EmailSignature::class)->text($employee);
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('my-email-signature', 'My Email Signature: Help')];
    }
}
