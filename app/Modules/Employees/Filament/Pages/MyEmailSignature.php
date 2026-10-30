<?php

namespace App\Modules\Employees\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\EmailSignature;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use UnitEnum;

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

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $title = 'My Email Signature';

    protected static ?int $navigationSort = 64;

    public static function canAccess(): bool
    {
        return static::moduleIsAvailable()
            && Employee::query()->where('user_id', auth()->id())->exists();
    }

    public function employee(): ?Employee
    {
        return Employee::query()->with('user')->where('user_id', auth()->id())->first();
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
