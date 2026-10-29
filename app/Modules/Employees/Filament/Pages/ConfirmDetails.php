<?php

namespace App\Modules\Employees\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Support\HelpAction;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeVerification;
use App\Modules\Employees\Services\VerificationCodes;
use App\Modules\Employees\Support\EmployeeChecks;
use App\Support\VerificationChecks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/**
 * Where somebody confirms their own details — docs/saas-launch-plan.md §14.8.
 *
 * The one screen a person held by the gate can still open, which is why it asks for
 * nothing but the codes: everything here is about the record the company already
 * holds, and a page that also let them *change* the number would let somebody past
 * the check by changing what the check is about.
 *
 * **Not in the sidebar.** It is reached by the gate sending somebody here, and by the
 * link on My Profile for anybody coming back to it after changing their number. A
 * sidebar entry would be an entry most people see as permanently finished, in a
 * group every other company member would see as empty — and the two-level shell
 * places items by label, which cannot place one that exists for some readers only.
 */
class ConfirmDetails extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.confirm-details';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static string|UnitEnum|null $navigationGroup = 'Employee';

    protected static ?string $title = 'Confirm Your Details';

    protected static ?int $navigationSort = 58;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return static::moduleIsAvailable() && EmployeeChecks::employeeFor(auth()->user()) !== null;
    }

    public function employee(): ?Employee
    {
        return EmployeeChecks::employeeFor(auth()->user());
    }

    /**
     * Each check, with where this person stands on it.
     *
     * @return array<int, array{kind: string, label: string, value: ?string, state: string, note: ?string}>
     */
    public function checks(): array
    {
        $employee = $this->employee();

        if ($employee === null) {
            return [];
        }

        $required = VerificationChecks::required();
        $proved = EmployeeChecks::proved($employee);
        $rows = [];

        foreach (EmployeeVerification::KINDS as $kind => $label) {
            $key = 'employee.'.$kind;

            if (! in_array($key, $required, true)) {
                continue;
            }

            $value = EmployeeVerification::valueOn($employee, $kind);

            $rows[] = [
                'kind' => $kind,
                'label' => $label,
                'value' => $this->shown($employee, $kind),
                'state' => match (true) {
                    $value === null => 'nothing-on-file',
                    ($proved[$kind] ?? false) => 'confirmed',
                    default => 'outstanding',
                },
                'note' => $value === null
                    ? 'There is nothing on your record to confirm. Ask HR to add it, then come back.'
                    : null,
            ];
        }

        return $rows;
    }

    /** Anything else the company requires that is not one of this module's three. */
    public function otherOutstanding(): array
    {
        $mine = array_map(fn (string $kind): string => 'employee.'.$kind, array_keys(EmployeeVerification::KINDS));

        return collect(VerificationChecks::outstandingFor(auth()->user()))
            ->reject(fn (string $reason, string $key): bool => in_array($key, $mine, true))
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('confirm-details', 'Confirm Your Details: Help')];
    }

    public function sendCodeAction(): Action
    {
        return Action::make('sendCode')
            ->label('Send a code')
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->modalHeading('Send a code')
            ->modalDescription(fn (array $arguments): string => $arguments['kind'] === EmployeeVerification::KIND_ADDRESS
                ? 'A letter with a code is produced for HR to post to your address. Confirming it is how the address is proved, so it cannot be emailed.'
                : 'A six-digit code goes to that number on WhatsApp. It is good for fifteen minutes.')
            ->action(function (array $arguments) {
                $employee = $this->employee();

                if ($employee === null) {
                    return null;
                }

                try {
                    $letter = app(VerificationCodes::class)->issue($employee, $arguments['kind']);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('It could not be sent.')->body($e->getMessage())->send();

                    return null;
                }

                if ($letter !== null) {
                    Notification::make()->success()->title('The letter is downloading. Give it to HR to post.')->send();

                    return response()->streamDownload(fn () => print $letter->raw(), $letter->getName(), ['Content-Type' => 'application/pdf']);
                }

                Notification::make()->success()->title('Sent. Type the code below when it arrives.')->send();

                return null;
            });
    }

    public function enterCodeAction(): Action
    {
        return Action::make('enterCode')
            ->label('I have the code')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->modalHeading('Type the code')
            ->schema([
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->maxLength(6)
                    ->autocomplete(false)
                    ->helperText(new HtmlString('Six digits. Five wrong tries and the code is burnt, and you send a new one.')),
            ])
            ->action(function (array $arguments, array $data): void {
                $employee = $this->employee();

                if ($employee === null) {
                    return;
                }

                $problem = app(VerificationCodes::class)->check($employee, $arguments['kind'], (string) $data['code']);

                $problem === null
                    ? Notification::make()->success()->title('Confirmed, thank you.')->send()
                    : Notification::make()->danger()->title($problem)->send();
            });
    }

    /** The value, partly hidden: enough to recognise, not enough to copy off a shared screen. */
    private function shown(Employee $employee, string $kind): ?string
    {
        if ($kind === EmployeeVerification::KIND_ADDRESS) {
            return $employee->postalAddress();
        }

        $raw = $kind === EmployeeVerification::KIND_PHONE ? $employee->phone : $employee->emergency_contact_phone;

        if (blank($raw)) {
            return null;
        }

        return str_repeat('•', max(0, strlen((string) $raw) - 3)).substr((string) $raw, -3);
    }
}
