<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Pages;

use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Filament\Resources\Employees\Schemas\EmployeeInfolist;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Services\AppointmentLetter;
use App\Modules\Employees\Services\CustomLetter;
use App\Modules\Employees\Services\ExperienceLetter;
use App\Modules\Employees\Services\IncomeCertificate;
use App\Support\Pdf\Pdf;
use App\Support\ResourceContributions;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    public function infolist(Schema $schema): Schema
    {
        return EmployeeInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            /**
             * Streamed, and nothing kept.
             *
             * This used to write `employees/employee-{id}-{time}.pdf` — CNIC, bank account, salary, address —
             * onto the company's disk and redirect to it. The route serving that disk checks company
             * membership, so it was never public; but every colleague could fetch it with the path, the path
             * was guessable from the id and the clock, and nothing ever deleted one. A document of somebody's
             * identity that outlives the click that made it is a liability with no owner. The letters were
             * built to stream from the start; this joins them.
             */
            Action::make('downloadPdf')
                ->label(__('Download PDF'))
                ->icon('heroicon-o-document-arrow-down')
                ->action(function (Employee $record) {
                    $pdf = Pdf::view('pdfs.employee', ['employee' => $record->load('user', 'bank', 'manager.user')])
                        ->format('a4');

                    // `raw()`, not the response's content — see the payslip download for the 0-byte PDF
                    // that `toResponse()->getContent()` produces under Dompdf.
                    return response()->streamDownload(
                        fn () => print ($pdf->raw()),
                        'employee-'.($record->employee_id ?: $record->getKey()).'.pdf',
                    );
                }),

            $this->incomeCertificateAction(),

            $this->appointmentLetterAction(),

            // What other modules add here — the first is Performance's "Award badge" (plan §8.4).
            // Offered as a slot rather than named, so this page never learns who fills it.
            ...ResourceContributions::headerActionsFor(static::class),

            // The dated moves — docs/saas-launch-plan.md §14.2.
            $this->transferAction(),
            $this->promotionAction(),
            $this->rehireAction(),

            $this->experienceLetterAction(),

            $this->customLetterAction(),

            EditAction::make(),
        ];
    }

    /**
     * The terms, for the person who has just joined — plan §7.2. The income certificate's facts and
     * position, for a different reader.
     */
    protected function appointmentLetterAction(): Action
    {
        return Action::make('appointmentLetter')
            ->label(__('Appointment letter'))
            ->icon('heroicon-o-document-check')
            ->color('gray')
            ->visible(fn (Employee $record): bool => auth()->user()?->can('view', $record) ?? false)
            ->schema([
                TextInput::make('probation_months')
                    ->label(__('Probation (months)'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(12)
                    ->default(3)
                    ->helperText('Zero prints no probation clause.'),
            ])
            ->modalSubmitActionLabel('Download')
            ->action(function (Employee $record, array $data) {
                $letters = app(AppointmentLetter::class);
                $missing = $letters->missingFor($record);

                if ($missing !== []) {
                    Notification::make()
                        ->danger()
                        ->title('The letter is missing facts it cannot invent')
                        ->body('Add '.implode('; ', $missing).'.')
                        ->persistent()
                        ->send();

                    return null;
                }

                $pdf = $letters->renderPdf($record, $data);

                return response()->streamDownload(
                    fn () => print ($pdf->raw()),
                    $letters->filename($record),
                );
            });
    }

    /**
     * Any wording an administrator types, on the company letterhead — the letter the other two don't cover.
     *
     * **Administrator only, unlike its siblings.** The certificate and the experience letter print only what
     * the record backs, so anyone who may view the record may download them. This prints whatever is typed,
     * and free text on company paper is a statement the company makes — so it is gated on who may speak for
     * the company, not on who may see the employee.
     */
    protected function customLetterAction(): Action
    {
        return Action::make('customLetter')
            ->label(__('Custom letter'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->isAdministrator() ?? false)
            ->modalHeading('Custom letter')
            ->modalDescription('Your own wording on the company letterhead, with the reference and seal. '
                .'Rendered fresh each time and never stored — keep your own copy of what you send.')
            ->modalSubmitActionLabel('Download')
            ->schema([
                TextInput::make('to')
                    ->label(__('Addressed to'))
                    ->maxLength(160)
                    ->default(fn (Employee $record): string => $record->fullName())
                    ->helperText('Printed above the subject. "To whom it may concern" works too; '
                        .'leave blank to omit the line.'),

                TextInput::make('subject')
                    ->label(__('Subject'))
                    ->required()
                    ->maxLength(160)
                    ->placeholder('Letter of Appreciation / Warning Letter / No Objection Certificate'),

                Textarea::make('body')
                    ->label(__('Body'))
                    ->required()
                    ->rows(12)
                    ->maxLength(8000)
                    ->helperText('Plain text, printed as typed. A blank line starts a new paragraph.'),
            ])
            ->action(function (Employee $record, array $data) {
                $letters = app(CustomLetter::class);

                $missing = $letters->missingFor($record);

                if ($missing !== []) {
                    Notification::make()
                        ->danger()
                        ->title('The letterhead is incomplete')
                        ->body('Add '.implode('; ', $missing).'.')
                        ->persistent()
                        ->send();

                    return null;
                }

                $pdf = $letters->renderPdf($record, $data);

                // `raw()` for the reason the sibling letters give: `toResponse()->getContent()` is false
                // under Dompdf, and `echo false` is a 0-byte PDF that looks like a document.
                return response()->streamDownload(
                    fn () => print ($pdf->raw()),
                    $letters->filename($record),
                );
            });
    }

    /**
     * "This person worked here, in these roles, for this long" — the letter a next employer asks for.
     *
     * Offered for somebody still employed as well as a leaver: an experience letter is asked for while
     * job-hunting, which by definition happens before the resignation. The letter reads in the present
     * tense until there is a leaving date on the record.
     *
     * **The salary is off by default and asking is a deliberate act.** What somebody earned here follows
     * them into their next negotiation, and volunteering it on a letter they hand over is not ours to do.
     * The toggle exists because some employers demand "last drawn salary" and refusing outright would send
     * the employee back to HR for a second letter.
     */
    protected function experienceLetterAction(): Action
    {
        return Action::make('experienceLetter')
            ->label(__('Experience letter'))
            ->icon('heroicon-o-document-check')
            ->color('gray')
            ->visible(fn (Employee $record): bool => auth()->user()?->can('view', $record) ?? false)
            ->modalHeading('Certificate of experience')
            ->modalDescription('States the period of service and the roles held, from the job history. '
                .'Rendered fresh each time and never stored.')
            ->modalSubmitActionLabel('Download')
            ->schema([
                Select::make('conduct')
                    ->label(__('Conduct and performance were found to be'))
                    ->options(fn (): array => options('employees.conduct'))
                    ->default('satisfactory')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->required()
                    ->helperText('Your company\'s own wording — edit the list under Settings, Dropdown Options.'),

                Textarea::make('duties')
                    ->label(__('Principal duties'))
                    ->rows(2)
                    ->maxLength(400)
                    ->helperText('One or two lines. Left blank, the sentence is omitted.'),

                TextInput::make('purpose')
                    ->label(__('What is it for? (optional)'))
                    ->maxLength(160)
                    ->placeholder('future employment / a visa application'),

                Toggle::make('include_salary')
                    ->label(__('Include the salary'))
                    ->helperText('Off by default, and worth leaving off: what somebody earned here is theirs '
                        .'to disclose at their next negotiation. Turn it on only when the recipient has '
                        .'actually asked for a last-drawn figure.'),
            ])
            ->action(function (Employee $record, array $data) {
                $letters = app(ExperienceLetter::class);

                $missing = $letters->missingFor($record);

                if ($missing !== []) {
                    Notification::make()
                        ->danger()
                        ->title('The letter is missing facts it cannot invent')
                        ->body('Add '.implode('; ', $missing).'.')
                        ->persistent()
                        ->send();

                    return null;
                }

                $pdf = $letters->renderPdf($record, $data);

                // `raw()` for the reason the payslip download gives: `toResponse()->getContent()` is false
                // under Dompdf, and `echo false` is a 0-byte PDF that looks like a document.
                return response()->streamDownload(
                    fn () => print ($pdf->raw()),
                    $letters->filename($record),
                );
            });
    }

    /**
     * "This person works here and this is what we pay them", as a letter for a bank or an embassy.
     *
     * **Offered to whoever may already view the record**, which is the employee themselves, their reporting
     * line and an administrator — the same people who can already see the salary it states. An employee
     * asking a bank for a loan is the whole reason this exists, so making it HR-only would leave the person
     * who needs it emailing somebody for it.
     *
     * Three things only a person can supply, and none of them is on the record: whose father's name goes on
     * it, what it is *for*, and the duties line. The rest is refused rather than guessed — see
     * `IncomeCertificate::missingFor()`.
     */
    protected function incomeCertificateAction(): Action
    {
        return Action::make('incomeCertificate')
            ->label(__('Income certificate'))
            ->icon('heroicon-o-identification')
            ->color('gray')
            ->visible(fn (Employee $record): bool => auth()->user()?->can('view', $record) ?? false)
            ->modalHeading('Certificate of employment and source of income')
            ->modalDescription('Rendered fresh each time from the record as it stands. Nothing is stored, so '
                .'a copy already given out never disagrees with the salary on file.')
            ->modalSubmitActionLabel('Download')
            ->schema([
                TextInput::make('purpose')
                    ->label(__('What is it for?'))
                    ->required()
                    ->maxLength(160)
                    ->placeholder('a visa application / opening a bank account / loan processing')
                    ->helperText('Printed on the letter. A certificate with no stated purpose is one the '
                        .'reader has to guess at.'),

                TextInput::make('father_name')
                    ->label("Father's / guardian's name")
                    ->maxLength(160)
                    ->helperText('Asked for here because it is not on the employee record. Banks and '
                        .'embassies match it against the CNIC; leave it blank to omit the clause.'),

                TextInput::make('residence')
                    ->label(__('Residential address'))
                    ->maxLength(255)
                    ->default(fn (Employee $record): string => trim(implode(', ', array_filter([
                        $record->address_line_1,
                        $record->address_line_2,
                    ]))))
                    ->helperText('From the employee record. Correct it here for this letter only.'),

                Textarea::make('duties')
                    ->label(__('Principal duties'))
                    ->rows(2)
                    ->maxLength(400)
                    ->helperText('One or two lines. Left blank, the sentence is omitted rather than filled '
                        .'with the job title twice.'),
            ])
            ->action(function (Employee $record, array $data) {
                $certificates = app(IncomeCertificate::class);

                $missing = $certificates->missingFor($record);

                if ($missing !== []) {
                    Notification::make()
                        ->danger()
                        ->title('The certificate is missing facts it cannot invent')
                        ->body('Add '.implode('; ', $missing).'.')
                        ->persistent()
                        ->send();

                    return null;
                }

                $pdf = $certificates->renderPdf($record, $data);

                // `raw()`, not the response's content — see the payslip download for the 0-byte PDF this
                // avoids on a host with no Node.
                return response()->streamDownload(
                    fn () => print ($pdf->raw()),
                    $certificates->filename($record),
                );
            });
    }

    protected function transferAction(): Action
    {
        return Action::make('recordTransfer')
            ->label(__('Record a transfer'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->visible(fn (Employee $record): bool => $record->is_active && (auth()->user()?->can('EmployeeUpdate') ?? false))
            ->schema([
                \Filament\Forms\Components\Select::make('department')->options(fn (): array => options('employees.department'))->nullable(),
                \Filament\Forms\Components\Select::make('location_id')->label(__('Location'))->options(fn (): array => \App\Modules\Core\Models\WorkLocation::options())->nullable(),
                \Filament\Forms\Components\Select::make('manager_id')->label(__('Manager'))
                    ->options(fn (Employee $record): array => Employee::query()->where('is_active', true)->whereKeyNot($record->getKey())->with('user')->orderBy('employee_id')->get()->mapWithKeys(fn (Employee $e): array => [$e->getKey() => $e->display_label])->all())
                    ->searchable()->nullable(),
                \Filament\Forms\Components\DatePicker::make('effective_from')->native(false)->default(now())->required(),
                \Filament\Forms\Components\TextInput::make('reason')->maxLength(190),
            ])
            ->action(function (Employee $record, array $data): void {
                try {
                    $row = app(\App\Modules\Employees\Services\EmployeeMoves::class)->transfer($record, $data, $data['effective_from'], $data['reason'] ?? null);
                } catch (\InvalidArgumentException $e) {
                    \Filament\Notifications\Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                static::notifyMoveRecorded($record, $row, 'Transfer');
            });
    }

    protected function promotionAction(): Action
    {
        return Action::make('recordPromotion')
            ->label(__('Record a promotion'))
            ->icon('heroicon-o-arrow-trending-up')
            ->color('gray')
            ->visible(fn (Employee $record): bool => $record->is_active && (auth()->user()?->can('EmployeeUpdate') ?? false))
            ->schema([
                \Filament\Forms\Components\Select::make('designation')->options(fn (): array => options('employees.designation'))->required(),
                \Filament\Forms\Components\DatePicker::make('effective_from')->native(false)->default(now())->required(),
                \Filament\Forms\Components\TextInput::make('reason')->maxLength(190),
            ])
            ->action(function (Employee $record, array $data): void {
                $row = app(\App\Modules\Employees\Services\EmployeeMoves::class)->promote($record, $data['designation'], $data['effective_from'], $data['reason'] ?? null);
                static::notifyMoveRecorded($record, $row, 'Promotion');
            });
    }

    /**
     * Say whether the move changed the record, not only that it was written down.
     *
     * A row moves the record only when it is the one in force today (JobHistory::syncCurrentValues). A
     * future date waits for its day; a date earlier than a later row — including the row an ordinary edit
     * of a job fact writes today — is history behind that row and never applies. Both used to say
     * "recorded" and nothing else, so a backdated transfer looked done while the record still showed the
     * old department.
     */
    protected static function notifyMoveRecorded(Employee $employee, \App\Modules\Employees\Models\EmployeeJobHistory $row, string $what): void
    {
        $inForce = app(\App\Modules\Employees\Services\JobHistory::class)->rowOn($employee, now());

        if ($inForce?->is($row)) {
            \Filament\Notifications\Notification::make()->success()->title("{$what} recorded and applied.")->send();

            return;
        }

        if ($row->effective_from->isAfter(now()->endOfDay())) {
            \Filament\Notifications\Notification::make()->success()
                ->title("{$what} recorded. It takes effect on ".$row->effective_from->format('d M Y').'.')
                ->body('The record keeps its current values until then; the nightly run moves it on the day.')
                ->send();

            return;
        }

        \Filament\Notifications\Notification::make()->warning()
            ->title("{$what} recorded in the history, but not applied to the record.")
            ->body('A later change, effective '.($inForce?->effective_from?->format('d M Y') ?? 'after it').', is the one in force, '
                .'so the record keeps what that change says. To move the record now, record it again effective today.')
            ->persistent()
            ->send();
    }

    protected function rehireAction(): Action
    {
        return Action::make('rehire')
            ->label(__('Rehire'))
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->visible(fn (Employee $record): bool => ! $record->is_active && (auth()->user()?->can('EmployeeUpdate') ?? false))
            ->modalDescription('Reopens the record as a new spell of employment. The old one stays in the job history.')
            ->schema([
                \Filament\Forms\Components\DatePicker::make('joined_on')->label(__('Rejoined on'))->native(false)->default(now())->required(),
                \Filament\Forms\Components\TextInput::make('reason')->maxLength(190),
            ])
            ->action(function (Employee $record, array $data): void {
                app(\App\Modules\Employees\Services\EmployeeMoves::class)->rehire($record, $data['joined_on'], $data['reason'] ?? null);
                \Filament\Notifications\Notification::make()->success()->title($record->fresh()->display_label.' is back on the books.')->send();
            });
    }
}
